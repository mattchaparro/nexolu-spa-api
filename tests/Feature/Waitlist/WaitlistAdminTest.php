<?php

namespace Tests\Feature\Waitlist;

use App\Models\Business;
use App\Models\Client;
use App\Models\Location;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Waitlist\WaitlistService;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La lista de espera vista desde el mostrador.
 *
 * Lo que se defiende: que la sede LIMITE lo que ve la empleada (una espera de
 * la otra sede es tan ajena como sus clientas), que "cualquier sede" lo vean
 * todas, y que cerrar una espera sea lo unico que se puede hacer desde aca.
 */
class WaitlistAdminTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Location $chapinero;

    private Location $cedritos;

    private Resource $maria;

    private Service $manicure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->chapinero = $this->business->primaryLocation();
        $this->cedritos = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Cedritos', 'slug' => 'cedritos',
            'is_primary' => false, 'is_active' => true,
        ]);

        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->manicure = $this->makeService($this->business, 60, [$this->maria]);
    }

    private function usuaria(bool $duena, ?Location $sede = null): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => $duena ? 'Dueña' : 'Encargada',
            'email' => uniqid().'@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'is_owner' => $duena,
        ]);
        PermissionCatalog::applyRole(
            $user,
            $duena ? PermissionCatalog::ROLE_ADMIN : PermissionCatalog::ROLE_RECEPTION,
        );

        if ($sede !== null) {
            $user->locations()->sync([$sede->id]);
        }

        return $user;
    }

    private function espera(string $nombre, ?int $locationId): WaitlistEntry
    {
        $clienta = Client::create([
            'business_id' => $this->business->id,
            'name' => $nombre,
            'phone' => '+57300'.random_int(1000000, 9999999),
            'is_active' => true,
        ]);

        $manana = CarbonImmutable::now('America/Bogota')->addDay()->startOfDay();

        return $this->app->make(WaitlistService::class)->register(
            $this->business,
            $clienta,
            $this->manicure,
            $clienta->phone,
            $manana,
            $manana->addDays(7),
            null,
            $locationId,
        );
    }

    public function test_la_duena_ve_todas_las_esperas(): void
    {
        $this->espera('Carolina', $this->chapinero->id);
        $this->espera('Lucia', $this->cedritos->id);
        $this->espera('Valentina', null);

        Sanctum::actingAs($this->usuaria(true)->fresh());

        $r = $this->getJson('/api/v1/waitlist')->assertOk();

        $this->assertCount(3, $r->json('data'));
        $this->assertSame(3, $r->json('open'));
    }

    public function test_la_empleada_de_una_sede_solo_ve_su_sede_y_lo_sin_sede(): void
    {
        $this->espera('Carolina', $this->chapinero->id);
        $this->espera('Lucia', $this->cedritos->id);
        $this->espera('Valentina', null);

        Sanctum::actingAs($this->usuaria(false, $this->cedritos)->fresh());

        $nombres = collect($this->getJson('/api/v1/waitlist')->assertOk()->json('data'))
            ->pluck('client_name');

        // Lucia (su sede) y Valentina (cualquier sede). Carolina es de la otra.
        $this->assertCount(2, $nombres);
        $this->assertNotContains('Carolina', $nombres);
    }

    public function test_pedir_la_sede_ajena_es_422_no_un_filtro_ignorado(): void
    {
        Sanctum::actingAs($this->usuaria(false, $this->cedritos)->fresh());

        $this->getJson('/api/v1/waitlist?location_id='.$this->chapinero->id)
            ->assertStatus(422);
    }

    public function test_cerrar_una_espera_deja_de_avisar(): void
    {
        $entry = $this->espera('Carolina', null);

        Sanctum::actingAs($this->usuaria(true)->fresh());

        $this->postJson("/api/v1/waitlist/{$entry->id}/stop")->assertOk();

        $this->assertSame(WaitlistEntry::STATUS_STOPPED, $entry->fresh()->status);
    }

    public function test_una_espera_de_otro_negocio_no_existe(): void
    {
        $entry = $this->espera('Carolina', null);

        // Un negocio ajeno con su propia dueña.
        $otro = $this->makeBusiness();
        $ajena = User::create([
            'business_id' => $otro->id,
            'name' => 'Ajena',
            'email' => 'ajena@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'is_owner' => true,
        ]);
        PermissionCatalog::applyRole($ajena, PermissionCatalog::ROLE_ADMIN);

        Sanctum::actingAs($ajena->fresh());

        $this->assertCount(0, $this->getJson('/api/v1/waitlist')->assertOk()->json('data'));
        $this->postJson("/api/v1/waitlist/{$entry->id}/stop")->assertNotFound();
        $this->assertSame(WaitlistEntry::STATUS_OPEN, $entry->fresh()->status);
    }

    public function test_el_filtro_por_estado_muestra_las_cerradas(): void
    {
        $abierta = $this->espera('Carolina', null);
        $cerrada = $this->espera('Lucia', null);
        DB::table('waitlist_entries')->where('id', $cerrada->id)
            ->update(['status' => WaitlistEntry::STATUS_STOPPED]);

        Sanctum::actingAs($this->usuaria(true)->fresh());

        // Sin filtro: solo las que esperan.
        $this->assertCount(1, $this->getJson('/api/v1/waitlist')->json('data'));

        $nombres = collect($this->getJson('/api/v1/waitlist?status=stopped')->json('data'))
            ->pluck('client_name');
        $this->assertSame(['Lucia'], $nombres->all());
    }
}
