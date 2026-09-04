<?php

namespace Tests\Feature\Closing;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientPhoto;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Services\Messaging\ServiceDoneReminder;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * "Terminaste, regístralo".
 *
 * El caso real: la cita empezó a las 2, el semipermanente dura noventa
 * minutos, y a las 3:30 la clienta se va. La manicurista sigue con la
 * siguiente y el servicio queda sin registrar hasta que alguien, en el cierre
 * del día, intenta reconstruir de memoria quién atendió qué.
 *
 * LO QUE MÁS SE DEFIENDE ACÁ es que venga APAGADO. Un aviso al equipo que se
 * enciende solo es mandarle WhatsApp a las empleadas de un negocio, a su
 * nombre, sin que nadie lo haya pedido.
 */
class ServiceDoneTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Service $semipermanente;

    protected function setUp(): void
    {
        parent::setUp();

        // Un miércoles a las 4 de la tarde: el servicio de las 2 ya terminó.
        $this->travelTo(CarbonImmutable::parse('2026-09-16 16:00', 'America/Bogota'));

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['service_done_reminder_min' => 10]);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->semipermanente = $this->makeService($this->business, 90, [$this->maria], name: 'Semipermanente');

        // Sin teléfono no hay aviso, y eso tiene su propia prueba más abajo.
        $this->maria->update(['user_id' => $this->usuaria('+573001112233')->id]);
    }

    public function test_el_servicio_que_termino_y_sigue_sin_registrar_dispara_el_aviso(): void
    {
        $this->cita('14:00');
        $this->assertSame(1, $this->recordar()['queued']);

        $mensaje = Message::withoutGlobalScopes()->where('kind', Message::KIND_SERVICE_DONE)->first();

        $this->assertNotNull($mensaje);
        // Sin el "+": ChannelPhone lo normaliza al formato que espera Meta.
        $this->assertSame('573001112233', $mensaje->to);
        $this->assertStringContainsString('Semipermanente', $mensaje->body);
    }

    public function test_viene_apagado_y_no_le_escribe_a_nadie(): void
    {
        // El default de plataforma es 0. Prender un aviso al equipo sin que
        // nadie lo pida es escribirle a las empleadas a nombre del negocio.
        $this->business->update(['scheduling_settings' => ['service_done_reminder_min' => 0]]);
        $this->cita('14:00');

        $this->assertSame(0, $this->recordar()['queued']);
        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_el_servicio_que_todavia_esta_en_curso_no_se_avisa(): void
    {
        // Empieza a las 15:40: a las 16:00 la clienta sigue en la silla.
        $this->cita('15:40');

        $this->assertSame(0, $this->recordar()['queued']);
    }

    public function test_lo_que_ya_se_cobro_no_se_recuerda(): void
    {
        // `checked_out_at` ES el estado de "ya quedó en el sistema". No hay
        // un segundo campo que pueda desincronizarse.
        $cita = $this->cita('14:00');
        $cita->forceFill(['checked_out_at' => now()])->save();

        $this->assertSame(0, $this->recordar()['queued']);
    }

    public function test_una_corrida_perdida_se_recupera_en_la_siguiente(): void
    {
        // Ventana abierta hacia atrás: si el servidor estuvo caído dos horas,
        // el aviso igual sale. Preguntar por un instante exacto sería que un
        // minuto de caída son los servicios de ese minuto sin avisar.
        $this->cita('13:00');

        $this->assertSame(1, $this->recordar()['queued']);
    }

    public function test_un_servicio_de_hace_medio_dia_ya_no_se_avisa(): void
    {
        /*
         * Pero acotada. Un aviso a las nueve de la noche sobre lo de las siete
         * de la mañana no lo va a resolver nadie, y sólo entrena a ignorar el
         * siguiente.
         */
        $this->business->update(['scheduling_settings' => [
            'service_done_reminder_min' => 10,
            'service_done_reminder_max_age_min' => 120,
        ]]);

        $this->cita('07:00');

        $this->assertSame(0, $this->recordar()['queued']);
    }

    public function test_correr_dos_veces_no_le_manda_dos_whatsapp_a_nadie(): void
    {
        $this->cita('14:00');

        $this->assertSame(1, $this->recordar()['queued']);
        $this->assertSame(0, $this->recordar()['queued']);

        // Lo garantiza el índice único de `messages`, no una bandera.
        $this->assertSame(1, Message::withoutGlobalScopes()->where('kind', Message::KIND_SERVICE_DONE)->count());
    }

    public function test_el_aviso_de_etapa_no_se_come_el_de_fin_de_servicio(): void
    {
        /*
         * LA TRAMPA DEL ÍNDICE ÚNICO. `messages` es único por
         * (appointment_id, kind): si los dos avisos al equipo compartieran
         * `kind`, el segundo se tragaría en silencio y el síntoma sería "a
         * veces no me llega" — la clase de bug que nadie reporta bien.
         */
        $cita = $this->cita('14:00');

        Message::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'kind' => Message::KIND_STAFF,
            'to' => '+573001112233',
            'appointment_id' => $cita->id,
            'body' => 'Tu cita cambió de etapa.',
            'status' => Message::STATUS_MANUAL,
        ]);

        $this->assertSame(1, $this->recordar()['queued']);
    }

    public function test_sin_telefono_no_se_avisa_pero_no_es_un_error(): void
    {
        // Hay recursos que son una cabina, y profesionales cuyo usuario nunca
        // registró el teléfono. El pendiente igual les sale en «Mi día».
        $this->maria->user->forceFill(['phone' => null])->save();
        $this->cita('14:00');

        $resultado = $this->recordar();

        $this->assertSame(0, $resultado['queued']);
        $this->assertSame(1, $resultado['skipped']);
    }

    /*
    |--------------------------------------------------------------------------
    | Qué dice que falta
    |--------------------------------------------------------------------------
    */

    public function test_con_la_politica_encendida_el_aviso_pide_la_foto(): void
    {
        $this->pidiendoFotos();
        $this->cita('14:00');

        $this->recordar();

        $this->assertStringContainsString(
            'sube la foto',
            Message::withoutGlobalScopes()->first()->body,
        );
    }

    public function test_una_barberia_no_pide_fotos_y_el_aviso_no_las_menciona(): void
    {
        // En una barbería no se acostumbra fotografiar la cara de nadie, que
        // es distinto de fotografiar unas manos.
        $this->cita('14:00');

        $this->recordar();

        $this->assertStringNotContainsString(
            'foto',
            Message::withoutGlobalScopes()->first()->body,
        );
    }

    public function test_un_servicio_marcado_sin_foto_no_la_pide(): void
    {
        // Un semipermanente transparente no produce nada que valga la pena
        // fotografiar. Pedirla igual entrena a subir cualquier cosa.
        $this->pidiendoFotos();
        $this->semipermanente->update(['requires_photo' => false]);
        $this->cita('14:00');

        $this->recordar();

        $this->assertStringNotContainsString('foto', Message::withoutGlobalScopes()->first()->body);
    }

    public function test_si_la_foto_ya_esta_no_se_vuelve_a_pedir(): void
    {
        $this->pidiendoFotos();
        $cita = $this->cita('14:00');

        ClientPhoto::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'client_id' => $cita->client_id,
            'appointment_item_id' => $cita->items->first()->id,
            'image_path' => 'negocios/1/trabajos/x.jpg',
            'taken_at' => now(),
        ]);

        $this->recordar();

        $this->assertStringNotContainsString('foto', Message::withoutGlobalScopes()->first()->body);
    }

    /*
    |--------------------------------------------------------------------------
    | Ayudas
    |--------------------------------------------------------------------------
    */

    /** @return array{queued: int, skipped: int} */
    private function recordar(): array
    {
        return $this->app->make(ServiceDoneReminder::class)->run($this->business->fresh());
    }

    private function pidiendoFotos(): void
    {
        $this->business->update(['scheduling_settings' => [
            'service_done_reminder_min' => 10,
            'service_photo_policy' => 'ask',
        ]]);
    }

    private function usuaria(?string $phone): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Maria',
            'email' => uniqid().'@prueba.test',
            'password' => Hash::make('password123'),
            'phone' => $phone,
            'is_active' => true,
            'is_owner' => false,
        ]);

        PermissionCatalog::applyRole($user, PermissionCatalog::ROLE_STAFF);

        return $user;
    }

    /** Una cita de 90 minutos que arranca a la hora dada, sin cobrar. */
    private function cita(string $hora): Appointment
    {
        $inicio = CarbonImmutable::parse("2026-09-16 {$hora}", 'America/Bogota');

        $clienta = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina',
            'phone' => '+57300'.random_int(1000000, 9999999),
            'is_active' => true,
        ]);

        /*
         * En UTC, como las escribe BookingService::windowFor.
         *
         * No es un detalle del test: pasarle a Eloquent un Carbon en hora de
         * Bogotá guarda "15:30" crudo en una columna que todo el resto del
         * sistema lee como UTC, y la cita queda cinco horas corrida. Escribir
         * la prueba como escribe la aplicación es lo que hace que pruebe algo.
         */
        $cita = Appointment::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'location_id' => $this->business->primaryLocation()?->id,
            'client_id' => $clienta->id,
            'client_name' => $clienta->name,
            'starts_at' => $inicio->utc(),
            'ends_at' => $inicio->addMinutes(90)->utc(),
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => 'panel',
        ]);

        AppointmentItem::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'appointment_id' => $cita->id,
            'service_id' => $this->semipermanente->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $inicio->utc(),
            'ends_at' => $inicio->addMinutes(90)->utc(),
            'service_starts_at' => $inicio->utc(),
            'service_ends_at' => $inicio->addMinutes(90)->utc(),
            'price' => 50000,
        ]);

        return $cita->fresh('items');
    }
}
