<?php

namespace Tests\Feature\Closing;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientPhoto;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Cerrar el servicio: la foto del trabajo y el comprobante de lo que entró.
 *
 * LO QUE ESTAS PRUEBAS DEFIENDEN es un equilibrio fácil de romper en un
 * refactor: que la manicurista pueda subir la foto de SU trabajo **sin** que
 * eso le abra la base de clientes.
 *
 * El rol de profesional no tiene `clientes.gestionar` — a propósito, la base
 * de clientes con teléfonos es el activo del negocio. Si la única forma de
 * subir una foto fuera esa ruta, habría que darle acceso a toda la clientela
 * para una función pequeña, y alguien lo haría.
 */
class ServiceClosingTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Resource $luisa;

    private Service $semipermanente;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->travelTo(CarbonImmutable::parse('2026-09-16 16:00', 'America/Bogota'));

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->luisa = $this->makeResource($this->business, 'Luisa');
        $this->semipermanente = $this->makeService($this->business, 90, [$this->maria], name: 'Semipermanente');
    }

    /*
    |--------------------------------------------------------------------------
    | La foto del trabajo
    |--------------------------------------------------------------------------
    */

    public function test_la_manicurista_sube_la_foto_de_su_trabajo_sin_acceso_a_clientes(): void
    {
        $maria = $this->profesional($this->maria);

        // El punto de la prueba: NO tiene el permiso de la ficha, y aun así
        // puede fotografiar lo que acaba de hacer.
        $this->assertFalse($maria->hasBusinessPermission('clientes.gestionar'));

        $cita = $this->cita($this->maria);

        Sanctum::actingAs($maria);

        $this->postJson("/api/v1/appointments/{$cita->id}/work-photo", [
            'photo' => UploadedFile::fake()->image('unas.jpg'),
        ])->assertCreated();

        $foto = ClientPhoto::withoutGlobalScopes()->first();

        $this->assertNotNull($foto);
        $this->assertSame($cita->items->first()->id, $foto->appointment_item_id);
    }

    public function test_la_foto_queda_fechada_cuando_se_hizo_el_trabajo_no_cuando_se_subio(): void
    {
        // Si alguien la sube al otro día, la foto sigue siendo del servicio
        // de ayer: es la referencia de qué se le hizo y cuándo.
        $cita = $this->cita($this->maria, '14:00');

        Sanctum::actingAs($this->profesional($this->maria));

        $this->postJson("/api/v1/appointments/{$cita->id}/work-photo", [
            'photo' => UploadedFile::fake()->image('unas.jpg'),
        ])->assertCreated();

        $this->assertSame(
            '2026-09-16 20:30:00',
            ClientPhoto::withoutGlobalScopes()->first()->taken_at->toDateTimeString(),
        );
    }

    public function test_no_se_puede_fotografiar_el_trabajo_de_otra(): void
    {
        // La cita completa puede tener tres servicios de tres personas.
        // Fotografiar el de otra no es asunto de uno.
        $cita = $this->cita($this->luisa);

        Sanctum::actingAs($this->profesional($this->maria));

        $this->postJson("/api/v1/appointments/{$cita->id}/work-photo", [
            'photo' => UploadedFile::fake()->image('unas.jpg'),
        ])->assertNotFound();
    }

    public function test_recepcion_si_puede_porque_cobra_por_las_demas(): void
    {
        $cita = $this->cita($this->maria);

        Sanctum::actingAs($this->recepcion());

        $this->postJson("/api/v1/appointments/{$cita->id}/work-photo", [
            'photo' => UploadedFile::fake()->image('unas.jpg'),
        ])->assertCreated();
    }

    public function test_el_consentimiento_se_puede_anotar_en_el_mismo_gesto(): void
    {
        // Es el mejor momento: la clienta acaba de levantarse de la silla y
        // está ahí mirándose las manos.
        $cita = $this->cita($this->maria);

        Sanctum::actingAs($this->profesional($this->maria));

        $this->postJson("/api/v1/appointments/{$cita->id}/work-photo", [
            'photo' => UploadedFile::fake()->image('unas.jpg'),
            'marketing_consent' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('marketing_consent', true);
    }

    public function test_sin_decir_nada_la_foto_queda_sin_permiso(): void
    {
        $cita = $this->cita($this->maria);

        Sanctum::actingAs($this->profesional($this->maria));

        $this->postJson("/api/v1/appointments/{$cita->id}/work-photo", [
            'photo' => UploadedFile::fake()->image('unas.jpg'),
        ])->assertJsonPath('marketing_consent', false);
    }

    public function test_una_cita_sin_ficha_lo_dice_en_vez_de_inventar_una(): void
    {
        $cita = $this->cita($this->maria);
        $cita->forceFill(['client_id' => null])->save();

        Sanctum::actingAs($this->profesional($this->maria));

        $this->postJson("/api/v1/appointments/{$cita->id}/work-photo", [
            'photo' => UploadedFile::fake()->image('unas.jpg'),
        ])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | El comprobante
    |--------------------------------------------------------------------------
    */

    public function test_el_comprobante_queda_colgado_de_la_cita(): void
    {
        /*
         * Es la imagen que hoy viaja por el grupo de WhatsApp junto a "uñas
         * semipermanente de cuarenta mil" — texto cuyo contenido ya está en
         * esta base. Con el comprobante acá, ese mensaje deja de hacer falta.
         */
        $cita = $this->cita($this->maria);

        Sanctum::actingAs($this->profesional($this->maria));

        $this->postJson("/api/v1/appointments/{$cita->id}/payment-proof", [
            'proof' => UploadedFile::fake()->image('transferencia.jpg'),
        ])->assertCreated();

        $this->assertNotNull($cita->fresh()->payment_proof_path);
    }

    public function test_subir_otro_comprobante_reemplaza_al_anterior(): void
    {
        // Es lo que uno quiere cuando la primera salió movida.
        $cita = $this->cita($this->maria);

        Sanctum::actingAs($this->profesional($this->maria));

        $this->postJson("/api/v1/appointments/{$cita->id}/payment-proof", [
            'proof' => UploadedFile::fake()->image('uno.jpg'),
        ])->assertCreated();

        $primero = $cita->fresh()->payment_proof_path;

        $this->postJson("/api/v1/appointments/{$cita->id}/payment-proof", [
            'proof' => UploadedFile::fake()->image('dos.jpg'),
        ])->assertCreated();

        $this->assertNotSame($primero, $cita->fresh()->payment_proof_path);
        Storage::disk('public')->assertMissing($primero);
    }

    public function test_el_comprobante_de_la_cita_ajena_no_se_toca(): void
    {
        $cita = $this->cita($this->luisa);

        Sanctum::actingAs($this->profesional($this->maria));

        $this->postJson("/api/v1/appointments/{$cita->id}/payment-proof", [
            'proof' => UploadedFile::fake()->image('transferencia.jpg'),
        ])->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que la pantalla necesita saber
    |--------------------------------------------------------------------------
    */

    public function test_mi_dia_dice_que_el_servicio_termino_y_que_falta_la_foto(): void
    {
        $this->business->update(['scheduling_settings' => ['service_photo_policy' => 'ask']]);

        $this->cita($this->maria, '14:00');

        Sanctum::actingAs($this->profesional($this->maria));

        $r = $this->getJson('/api/v1/my-work')->assertOk();

        $this->assertTrue($r->json('pending_checkout.0.is_done'));
        $this->assertTrue($r->json('pending_checkout.0.needs_photo'));
        $this->assertFalse($r->json('pending_checkout.0.has_photo'));
    }

    public function test_mi_dia_no_pide_foto_si_el_negocio_no_las_pide(): void
    {
        $this->cita($this->maria, '14:00');

        Sanctum::actingAs($this->profesional($this->maria));

        $this->getJson('/api/v1/my-work')
            ->assertOk()
            ->assertJsonPath('pending_checkout.0.needs_photo', false);
    }

    public function test_el_servicio_en_curso_no_se_ve_como_atrasado(): void
    {
        // Empieza a las 15:40: a las 16:00 la clienta sigue en la silla, y
        // pintarlo igual que un pendiente sería mentir sobre el atraso.
        $this->cita($this->maria, '15:40');

        Sanctum::actingAs($this->profesional($this->maria));

        $this->getJson('/api/v1/my-work')
            ->assertOk()
            ->assertJsonPath('pending_checkout.0.is_done', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Ayudas
    |--------------------------------------------------------------------------
    */

    private function profesional(Resource $resource): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => $resource->name,
            'email' => uniqid().'@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'is_owner' => false,
        ]);

        PermissionCatalog::applyRole($user, PermissionCatalog::ROLE_STAFF);
        $resource->update(['user_id' => $user->id]);

        return $user->fresh();
    }

    private function recepcion(): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Encargada',
            'email' => uniqid().'@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'is_owner' => false,
        ]);

        PermissionCatalog::applyRole($user, PermissionCatalog::ROLE_RECEPTION);

        return $user->fresh();
    }

    /** Una cita de 90 minutos de esa profesional, sin cobrar. */
    private function cita(Resource $resource, string $hora = '14:00'): Appointment
    {
        $inicio = CarbonImmutable::parse("2026-09-16 {$hora}", 'America/Bogota');

        $clienta = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina',
            'phone' => '+57300'.random_int(1000000, 9999999),
            'is_active' => true,
        ]);

        $cita = Appointment::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'location_id' => $this->business->primaryLocation()?->id,
            'client_id' => $clienta->id,
            'client_name' => $clienta->name,
            // En UTC, como las escribe BookingService::windowFor.
            'starts_at' => $inicio->utc(),
            'ends_at' => $inicio->addMinutes(90)->utc(),
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => 'panel',
        ]);

        AppointmentItem::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'appointment_id' => $cita->id,
            'service_id' => $this->semipermanente->id,
            'resource_id' => $resource->id,
            'starts_at' => $inicio->utc(),
            'ends_at' => $inicio->addMinutes(90)->utc(),
            'service_starts_at' => $inicio->utc(),
            'service_ends_at' => $inicio->addMinutes(90)->utc(),
            'price' => 50000,
        ]);

        return $cita->fresh('items');
    }
}
