<?php

namespace Tests\Feature\Clients;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\ServiceRating;
use App\Models\User;
use App\Services\Ratings\SurveyService;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La encuesta que se manda cuando termina el servicio.
 *
 * Todo lo que se prueba acá sale del mismo desastre: Blue Souls tiene un
 * comando que recupera calificaciones **parseándolas de los logs de Laravel**,
 * porque llegaban, se logueaban y no se guardaban. La única copia de lo que
 * opinaron los clientes durante meses fue un archivo de texto rotativo.
 *
 * De ahí la regla que gobierna estas pruebas: UNA RESPUESTA QUE LLEGA NO SE
 * PIERDE.
 */
class SurveyTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Resource $ana;

    private Service $service;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60, 'min_booking_notice_min' => 0]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo', 'counts_as_cash' => true,
        ]);

        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00');
        $this->ana = $this->makeResource($this->business, 'Ana', '09:00:00', '18:00:00');

        $this->service = $this->makeService($this->business, 60, [$this->maria, $this->ana]);
        $this->service->update(['name' => 'Manicure', 'price' => 50000, 'commission_rate' => 0.30]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    private function citaConToken(array $extra = []): array
    {
        $id = $this->postJson('/api/v1/appointments', array_merge([
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Carolina',
            'client_phone' => '3001234567',
        ], $extra))->assertCreated()->json('id');

        $appointment = Appointment::withoutGlobalScope('business')->find($id);
        $token = app(SurveyService::class)->tokenFor($appointment);

        return [$appointment, $token];
    }

    public function test_el_enlace_dice_a_quien_hay_que_calificar(): void
    {
        [$appointment, $token] = $this->citaConToken();

        $form = $this->getJson("/api/v1/survey/{$token}")->assertOk();

        $this->assertFalse($form->json('answered'));
        $this->assertCount(1, $form->json('items'));
        $this->assertSame('Maria', $form->json('items.0.resource_name'));
    }

    public function test_el_token_no_es_el_id_de_la_cita(): void
    {
        // Un enlace con el id deja calificar las citas de otros probando
        // números.
        [$appointment, $token] = $this->citaConToken();

        $this->assertNotSame((string) $appointment->id, $token);
        $this->getJson("/api/v1/survey/{$appointment->id}")->assertNotFound();
    }

    public function test_se_guarda_la_calificacion(): void
    {
        [$appointment, $token] = $this->citaConToken();
        $itemId = $appointment->items()->first()->id;

        $this->postJson("/api/v1/survey/{$token}", [
            'answers' => [[
                'item_id' => $itemId,
                'service_rating' => 5,
                'staff_rating' => 4,
                'punctuality_rating' => 3,
                'comment' => 'Quedé feliz.',
            ]],
        ])->assertCreated();

        $rating = ServiceRating::withoutGlobalScope('business')->first();

        $this->assertSame(5, $rating->service_rating);
        $this->assertSame(4, $rating->staff_rating);
        $this->assertSame($this->maria->id, $rating->resource_id);
        $this->assertSame('Quedé feliz.', $rating->comment);
        $this->assertNotNull($appointment->fresh()->survey_answered_at);
    }

    public function test_una_respuesta_incompleta_igual_se_guarda(): void
    {
        /*
         * Rechazar la respuesta entera por un campo que el cliente no llenó es
         * exactamente como se pierden. Se guarda lo que vino.
         */
        [$appointment, $token] = $this->citaConToken();

        $this->postJson("/api/v1/survey/{$token}", [
            'answers' => [[
                'item_id' => $appointment->items()->first()->id,
                'staff_rating' => 5,
            ]],
        ])->assertCreated();

        $rating = ServiceRating::withoutGlobalScope('business')->first();

        $this->assertSame(5, $rating->staff_rating);
        $this->assertNull($rating->punctuality_rating);
    }

    public function test_una_nota_fuera_de_rango_no_tumba_el_resto(): void
    {
        // Se descarta en silencio esa nota; lo demás que escribió sigue
        // valiendo.
        [$appointment, $token] = $this->citaConToken();

        $this->postJson("/api/v1/survey/{$token}", [
            'answers' => [[
                'item_id' => $appointment->items()->first()->id,
                'staff_rating' => 99,
                'service_rating' => 5,
                'comment' => 'Todo bien.',
            ]],
        ])->assertCreated();

        $rating = ServiceRating::withoutGlobalScope('business')->first();

        $this->assertNull($rating->staff_rating);
        $this->assertSame(5, $rating->service_rating);
        $this->assertSame('Todo bien.', $rating->comment);
    }

    public function test_lo_que_llego_queda_guardado_tal_cual(): void
    {
        /*
         * Es el seguro contra el desastre del sistema viejo: si mañana cambia
         * el formulario o llega algo que no se entiende, la respuesta igual
         * queda y se puede reinterpretar. Lo que no se puede es volver a
         * pedirle la opinión a alguien que ya la dio.
         */
        [$appointment, $token] = $this->citaConToken();

        $this->postJson("/api/v1/survey/{$token}", [
            'answers' => [['item_id' => $appointment->items()->first()->id, 'staff_rating' => 5]],
            'campo_que_no_conocemos' => 'algo',
        ])->assertCreated();

        $raw = ServiceRating::withoutGlobalScope('business')->first()->raw_payload;

        $this->assertSame('algo', $raw['campo_que_no_conocemos']);
    }

    public function test_no_se_le_cuelga_una_calificacion_a_la_cita_de_otro(): void
    {
        [$otra] = $this->citaConToken();
        [$appointment, $token] = $this->citaConToken(['starts_at' => $this->hoy()->format('Y-m-d').' 11:00:00']);

        $this->postJson("/api/v1/survey/{$token}", [
            'answers' => [[
                'item_id' => $otra->items()->first()->id,
                'staff_rating' => 1,
            ]],
        ])->assertCreated();

        // Se guardó -- no se pierde -- pero SIN atribuir a la línea ajena.
        $rating = ServiceRating::withoutGlobalScope('business')->first();
        $this->assertNull($rating->appointment_item_id);
        $this->assertSame($appointment->id, $rating->appointment_id);
    }

    public function test_una_garantia_no_se_califica(): void
    {
        // La visita existe porque algo salió mal; pedir estrellas ahí es
        // preguntar por el clavo en la herida.
        [$appointment, $token] = $this->citaConToken([
            'starts_at' => $this->hoy()->format('Y-m-d').' 12:00:00',
            'is_warranty' => true,
            'warranty_for_resource_id' => $this->maria->id,
        ]);

        $this->assertSame([], $this->getJson("/api/v1/survey/{$token}")->json('items'));
    }

    public function test_la_liquidacion_muestra_como_la_calificaron(): void
    {
        [$appointment, $token] = $this->citaConToken();

        $this->postJson("/api/v1/survey/{$token}", [
            'answers' => [[
                'item_id' => $appointment->items()->first()->id,
                'staff_rating' => 4,
                'comment' => 'Muy amable.',
            ]],
        ])->assertCreated();

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();

        $this->assertSame(1, $preview->json('ratings.count'));
        $this->assertEqualsWithDelta(4, $preview->json('ratings.staff_average'), 0.01);
        // El promedio ignora lo que nadie contestó, no lo trata como cero.
        $this->assertNull($preview->json('ratings.punctuality_average'));
        $this->assertSame('Muy amable.', $preview->json('ratings.comments.0.comment'));
    }
}
