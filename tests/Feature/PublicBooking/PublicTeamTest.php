<?php

namespace Tests\Feature\PublicBooking;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Location;
use App\Models\Resource;
use App\Models\ServiceRating;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La sección de colaboradores: quién te atiende, con foto, reseña y puntuación.
 *
 * Lo que se defiende es que publicar la nota de alguien sea una DECISIÓN, no un
 * efecto secundario. Es un número en la vitrina del local que dice qué tan
 * buena es una persona en su trabajo.
 */
class PublicTeamTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::now('America/Bogota')->startOfDay()->setTime(10, 0));

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();
        $this->business->update(['slug' => 'spa-equipo']);

        $this->maria = $this->makeResource($this->business, 'Maria');

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'),
            'is_active' => true, 'is_owner' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);
    }

    /**
     * Una calificación por visita.
     *
     * Cada una cuelga de su cita porque el índice único de `service_ratings`
     * es `(appointment_id, appointment_item_id)`: una persona califica una vez
     * por visita, no una vez por ganas.
     */
    private function calificar(Resource $quien, array $notas): void
    {
        foreach ($notas as $i => $nota) {
            $cita = Appointment::create([
                'business_id' => $this->business->id,
                'client_name' => 'Clienta '.$i,
                'starts_at' => CarbonImmutable::now()->subDays($i + 1),
                'ends_at' => CarbonImmutable::now()->subDays($i + 1)->addHour(),
                'status' => Appointment::STATUS_COMPLETED,
            ]);

            ServiceRating::create([
                'business_id' => $this->business->id,
                'appointment_id' => $cita->id,
                'resource_id' => $quien->id,
                'staff_rating' => $nota,
                'raw_payload' => ['staff_rating' => $nota],
            ]);
        }
    }

    private function publicar(bool $mostrarNotas): void
    {
        $this->business->update([
            'public_profile' => array_merge(
                $this->business->public_profile ?? [],
                ['show_staff_ratings' => $mostrarNotas],
            ),
        ]);
    }

    private function equipo(): array
    {
        return $this->getJson('/api/v1/public/spa-equipo')->assertOk()->json('team');
    }

    public function test_muestra_foto_nombre_y_resena(): void
    {
        $this->maria->update(['bio' => 'Especialista en acrílicas, 8 años de experiencia.']);

        $equipo = $this->equipo();

        $this->assertCount(1, $equipo);
        $this->assertSame('Maria', $equipo[0]['name']);
        $this->assertSame('Especialista en acrílicas, 8 años de experiencia.', $equipo[0]['bio']);
    }

    public function test_la_puntuacion_viene_apagada(): void
    {
        /*
         * Publicar la nota de alguien es una decisión sobre una persona real,
         * no una preferencia de diseño. Una manicurista con 4.1 al lado de una
         * con 4.9 en la vitrina es una conversación que el dueño tiene que
         * querer tener.
         */
        $this->calificar($this->maria, [5, 5, 4, 5, 5]);

        $this->assertNull($this->equipo()[0]['rating']);
    }

    public function test_encendida_muestra_el_promedio_y_de_cuantas(): void
    {
        $this->publicar(true);
        $this->calificar($this->maria, [5, 5, 4, 5, 5]);

        $equipo = $this->equipo();

        $this->assertSame(4.8, $equipo[0]['rating']);
        // "4.8" solo, sin decir de cuántas, es una cifra que no se puede juzgar.
        $this->assertSame(5, $equipo[0]['ratings_count']);
    }

    public function test_con_pocas_notas_no_se_publica_ninguna(): void
    {
        // Con dos respuestas, una clienta que tuvo un mal día deja a alguien
        // en 3.0 para siempre en la vitrina del local.
        $this->publicar(true);
        $this->calificar($this->maria, [3, 5]);

        $this->assertNull($this->equipo()[0]['rating']);
    }

    public function test_quien_no_toma_reservas_en_linea_igual_sale_en_la_vitrina(): void
    {
        /*
         * Son dos preguntas distintas: `is_bookable_online` dice CON QUIÉN SE
         * PUEDE RESERVAR, `is_public` dice A QUIÉN VAS A ENCONTRAR. Una
         * manicurista cuya agenda maneja el mostrador no acepta reservas por
         * internet y aun así merece estar en la página.
         */
        $this->maria->update(['is_bookable_online' => false]);

        $this->assertCount(1, $this->equipo());
        // Y no aparece en el selector de "con quién reservar", que es el otro
        // conjunto.
        $this->assertCount(0, $this->getJson('/api/v1/public/spa-equipo')->json('resources'));
    }

    public function test_se_puede_sacar_a_alguien_de_la_vitrina(): void
    {
        $this->maria->update(['is_public' => false]);

        $this->assertCount(0, $this->equipo());
    }

    public function test_la_resena_se_guarda_desde_el_panel(): void
    {
        Sanctum::actingAs($this->admin->fresh());

        $this->postJson("/api/v1/resources/{$this->maria->id}", [
            'bio' => 'Uñas esculpidas y nail art.',
            'is_public' => true,
        ])->assertOk();

        $this->assertSame('Uñas esculpidas y nail art.', $this->maria->fresh()->bio);
    }

    public function test_la_resena_se_puede_borrar(): void
    {
        // `present` en la validación: sin eso, null se vería igual que "no lo
        // mandaste" y no habría forma de quitarla una vez escrita.
        $this->maria->update(['bio' => 'Algo que ya no aplica.']);
        Sanctum::actingAs($this->admin->fresh());

        $this->postJson("/api/v1/resources/{$this->maria->id}", ['bio' => null])->assertOk();

        $this->assertNull($this->maria->fresh()->bio);
    }

    public function test_apagar_la_puntuacion_la_apaga_de_verdad(): void
    {
        /*
         * Un booleano se guarda SIEMPRE, también en false. Los campos de texto
         * se omiten cuando vienen vacíos -- así el titular cae al nombre del
         * negocio -- pero con un interruptor eso significaría que apagarlo no
         * lo apaga: al releer, el ausente vuelve al default.
         */
        $this->publicar(true);
        Sanctum::actingAs($this->admin->fresh());

        $this->postJson('/api/v1/public-page', [
            'headline' => 'Uñas que hablan por ti',
            'show_staff_ratings' => false,
        ])->assertOk();

        $this->assertFalse($this->business->fresh()->public_profile['show_staff_ratings']);
    }

    public function test_el_equipo_publico_es_el_de_la_sede_del_enlace(): void
    {
        $cedritos = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Cedritos', 'slug' => 'cedritos',
            'is_primary' => false, 'is_active' => true,
        ]);
        $this->makeResource($this->business, 'Lucia', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $cedritos->id);

        $nombres = array_column(
            $this->getJson('/api/v1/public/spa-equipo?location=cedritos')->assertOk()->json('team'),
            'name',
        );

        $this->assertSame(['Lucia'], $nombres);
    }
}
