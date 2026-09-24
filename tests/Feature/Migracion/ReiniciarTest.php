<?php

namespace Tests\Feature\Migracion;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Dejar el negocio listo para re-importar, sin borrarlo.
 *
 * Existe porque lo alternativo es hacerlo a mano en la base el día del
 * cambio: se olvida una tabla, o se borra el negocio entero y con él su
 * configuración, o se queda alguien sin usuario para entrar.
 */
class ReiniciarTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Service $servicio;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->business->forceFill([
            'slug' => 'luxury-nails',
            // La configuración que NO se puede perder: reconfigurarla a mano
            // el día del cambio es como se llega al sábado sin WhatsApp.
            'whatsapp_phone_number_id' => '111222333',
            'messaging_mode' => 'auto',
        ])->save();

        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->servicio = $this->makeService($this->business, 60, [$this->maria]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Alejandra',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'),
            'is_active' => true, 'is_owner' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo',
            'counts_as_cash' => true, 'is_active' => true, 'sort_order' => 1,
        ]);

        $cliente = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina', 'phone' => '+573001112233', 'is_active' => true,
        ]);

        $cita = Appointment::create([
            'business_id' => $this->business->id,
            'location_id' => $this->business->locations()->first()->id,
            'client_id' => $cliente->id,
            'client_name' => 'Carolina',
            'starts_at' => CarbonImmutable::now()->addDay(),
            'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
            'status' => Appointment::STATUS_PENDING,
        ]);

        // El libro de lo ya importado: si no se borra, la siguiente corrida
        // da todo por traído y no trae nada.
        DB::table('legacy_map')->insert([
            'business_id' => $this->business->id,
            'entity' => 'appointment',
            'legacy_id' => 9001,
            'new_id' => $cita->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function reiniciar(): void
    {
        $this->artisan('luxury:reiniciar --negocio=luxury-nails --force')->assertSuccessful();
    }

    public function test_borra_lo_que_el_importador_vuelve_a_traer(): void
    {
        $this->reiniciar();

        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
        $this->assertSame(0, Service::withoutGlobalScopes()->count());
        $this->assertSame(0, Resource::withoutGlobalScopes()->count());
    }

    public function test_borra_el_libro_de_lo_ya_importado(): void
    {
        /*
         * Es lo que más fácil se olvida y lo que peor falla: si `legacy_map`
         * se queda, la siguiente corrida cree que ya trajo todo y la agenda
         * amanece vacía.
         */
        $this->reiniciar();

        $this->assertSame(0, DB::table('legacy_map')->count());
    }

    public function test_el_negocio_conserva_su_configuracion(): void
    {
        // Sin esto habría que volver a cablear el número de WhatsApp y los
        // ajustes justo el día del cambio.
        $this->reiniciar();

        $negocio = Business::withoutGlobalScopes()->find($this->business->id);

        $this->assertNotNull($negocio);
        $this->assertSame('111222333', $negocio->whatsapp_phone_number_id);
        $this->assertSame('auto', $negocio->messaging_mode);
    }

    public function test_no_deja_a_nadie_sin_con_que_entrar(): void
    {
        // Quedarse sin usuario mientras se migra es el peor momento posible.
        $this->reiniciar();

        $admin = User::withoutGlobalScopes()->find($this->admin->id);

        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasBusinessPermission('nomina.gestionar'));
    }

    public function test_conserva_las_sedes_y_los_medios_de_pago(): void
    {
        // Las citas importadas se cuelgan de la sede, y los medios de pago
        // ya los configuró el negocio: el importador no los inventa.
        $this->reiniciar();

        $this->assertSame(1, Location::withoutGlobalScopes()->where('business_id', $this->business->id)->count());
        $this->assertSame(1, PaymentMethod::withoutGlobalScopes()->where('business_id', $this->business->id)->count());
    }

    public function test_no_toca_a_otro_negocio(): void
    {
        $otro = $this->makeBusiness();
        $suCliente = Client::create([
            'business_id' => $otro->id, 'name' => 'Ajena',
            'phone' => '+573009998877', 'is_active' => true,
        ]);

        $this->reiniciar();

        $this->assertNotNull(Client::withoutGlobalScopes()->find($suCliente->id));
    }

    public function test_sin_force_pregunta_y_no_borra_si_se_dice_que_no(): void
    {
        $this->artisan('luxury:reiniciar --negocio=luxury-nails')
            ->expectsConfirmation('¿Seguro? Esto no se puede deshacer.', 'no')
            ->assertSuccessful();

        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }
}
