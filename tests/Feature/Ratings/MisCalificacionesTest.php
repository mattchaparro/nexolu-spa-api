<?php

namespace Tests\Feature\Ratings;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Resource;
use App\Models\ServiceRating;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Lo que una profesional ve de sus propias calificaciones.
 *
 * El dueño lo pidio asi: "me gustaria que los empleados vean sus
 * calificaciones y que eso los motive a mejorar". Las dos mitades importan --
 * que las vea, y que lo que vea no la desanime por un error de lectura.
 */
class MisCalificacionesTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Resource $lucia;

    private User $empleada;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();
        $this->maria = $this->makeResource($this->business, 'Maria', '08:00:00', '20:00:00');
        $this->lucia = $this->makeResource($this->business, 'Lucia', '08:00:00', '20:00:00');

        $this->empleada = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        // El vinculo va del lado del recurso (`resources.user_id`), no del
        // usuario: `User::resource()` es un hasOne.
        $this->maria->update(['user_id' => $this->empleada->id]);
        PermissionCatalog::applyRole($this->empleada, PermissionCatalog::ROLE_STAFF);

        Sanctum::actingAs($this->empleada->fresh());
    }

    /** Una visita cualquiera: la calificacion cuelga de una, no flota. */
    private function visita(): Appointment
    {
        $desde = now($this->business->businessTimezone());

        return Appointment::create([
            'business_id' => $this->business->id,
            'starts_at' => $desde->utc(),
            'ends_at' => $desde->copy()->addHour()->utc(),
        ]);
    }

    private function calificar(Resource $de, array $datos = []): ServiceRating
    {
        return ServiceRating::create($datos + [
            'appointment_id' => $this->visita()->id,
            'business_id' => $this->business->id,
            'resource_id' => $de->id,
            'staff_rating' => 5, 'staff_scale' => 5,
            'service_rating' => 4, 'service_scale' => 4,
            'punctuality_rating' => 3, 'punctuality_scale' => 3,
        ]);
    }

    public function test_ve_sus_calificaciones(): void
    {
        $this->calificar($this->maria, ['comment' => 'Quedaron divinas.']);

        $this->getJson('/api/v1/my-work')
            ->assertOk()
            ->assertJsonPath('ratings.count', 1)
            ->assertJsonPath('ratings.comments.0.comment', 'Quedaron divinas.');
    }

    public function test_no_ve_las_de_su_companera(): void
    {
        /*
         * No es timidez con el dato: dos manicuristas que trabajan a un metro
         * viendo la nota de la otra convierte una herramienta en un problema
         * entre ellas. Cada una ve como va; quien compara es quien paga.
         */
        $this->calificar($this->lucia, ['comment' => 'Lucia es un amor.']);

        $this->getJson('/api/v1/my-work')
            ->assertOk()
            ->assertJsonPath('ratings.count', 0)
            ->assertJsonPath('ratings.comments', []);
    }

    public function test_el_comentario_no_dice_quien_lo_escribio(): void
    {
        // La base de clientas es del negocio: sirve lo que opinaron, no quien.
        $cliente = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina',
            'phone' => '3001112233',
        ]);

        $this->calificar($this->maria, ['comment' => 'Excelente.', 'client_id' => $cliente->id]);

        $respuesta = $this->getJson('/api/v1/my-work')->assertOk();

        $this->assertSame(
            ['comment', 'attention', 'date'],
            array_keys($respuesta->json('ratings.comments.0')),
        );

        $respuesta->assertDontSee('Carolina')->assertDontSee('3001112233');
    }

    public function test_el_tope_de_cada_escala_se_lee_como_cien(): void
    {
        /*
         * ESTO es lo que decidia si la pantalla motiva o desanima.
         *
         * La encuesta vieja preguntaba puntualidad con tres botones. Leido
         * crudo, un 3 perfecto se veia como "3" al lado de una atencion de
         * "5", y quien lo mirara creeria que llega tarde siempre.
         */
        $this->calificar($this->maria);

        $respuesta = $this->getJson('/api/v1/my-work')->assertOk();

        // `assertEquals` y no `assertSame`: al serializar, 100.0 viaja como
        // 100 y comparar el tipo seria probar el codificador de JSON.
        foreach (['attention', 'service', 'punctuality'] as $eje) {
            $this->assertEquals(100.0, $respuesta->json("ratings.{$eje}"), $eje);
        }
    }

    public function test_sin_calificaciones_no_inventa_un_cero(): void
    {
        // Un cero grande el primer dia desanima por algo que nadie opino.
        $this->getJson('/api/v1/my-work')
            ->assertOk()
            ->assertJsonPath('ratings.count', 0)
            ->assertJsonPath('ratings.attention', null);
    }

    public function test_compara_contra_el_mes_anterior_completo(): void
    {
        $ahora = now($this->business->businessTimezone());

        $this->calificar($this->maria)->forceFill([
            'created_at' => $ahora->copy()->subMonth()->startOfMonth()->addDays(2)->utc(),
        ])->save();

        $this->calificar($this->maria, ['staff_rating' => 4]);

        $respuesta = $this->getJson('/api/v1/my-work')->assertOk();

        $this->assertSame(1, $respuesta->json('ratings.this_month.count'));
        $this->assertSame(1, $respuesta->json('ratings.previous_month.count'));
        $this->assertEquals(100.0, $respuesta->json('ratings.previous_month.attention'));
        $this->assertEquals(75.0, $respuesta->json('ratings.this_month.attention'));
    }
}
