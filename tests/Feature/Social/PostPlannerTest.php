<?php

namespace Tests\Feature\Social;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientPhoto;
use App\Models\Resource;
use App\Models\Service;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Services\Social\PostDispatcher;
use App\Services\Social\PostPlanner;
use App\Support\BusinessFeaturePresets;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El planificador: de donde salen las ideas, y el reloj que las libera.
 *
 * Es la mitad del modulo que justifica que viva dentro de la API de agenda.
 * Un programador de publicaciones cualquiera abre un calendario vacio; este
 * lo llena con lo que la base ya sabe -- una foto de ayer, el jueves sin
 * nadie, el servicio que se dejo de vender.
 *
 * DOS INVARIANTES QUE SE DEFIENDEN AQUI CON PRUEBA PROPIA:
 *
 * 1. Ninguna foto sin consentimiento se propone. Nunca, por ningun camino.
 * 2. El reloj NO PUBLICA. Mueve `scheduled` a `ready` y se detiene.
 */
class PostPlannerTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private ?Resource $quienAtiende = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Un lunes: los cinco dias del horizonte caen en dias laborales.
        $this->travelTo(CarbonImmutable::parse('2026-09-14 07:00', 'America/Bogota'));

        $this->business = $this->makeBusiness();
    }

    /*
    |--------------------------------------------------------------------------
    | Las fotos
    |--------------------------------------------------------------------------
    */

    public function test_una_foto_reciente_con_permiso_se_propone_sola(): void
    {
        $foto = $this->foto(consentida: true, dias: 1);

        $this->planificar();

        $post = $this->publicacionDeLaFoto($foto);

        $this->assertNotNull($post);
        $this->assertSame(SocialPost::ANGLE_WORK, $post->angle);
        $this->assertSame(SocialPost::SOURCE_AUTO, $post->source);

        // Sin texto: escribirlo cuesta tokens y todavia nadie dijo que esta
        // idea valga la pena.
        $this->assertNull($post->caption);
    }

    public function test_una_foto_sin_permiso_no_se_propone_nunca(): void
    {
        $this->foto(consentida: false, dias: 1);

        $this->planificar();

        $this->assertSame(0, SocialPostImage::withoutGlobalScopes()->whereNotNull('client_photo_id')->count());
    }

    public function test_una_foto_vieja_ya_no_es_noticia(): void
    {
        // "Miren lo de ayer" con una foto de hace un mes no es una noticia.
        $this->foto(consentida: true, dias: 40);

        $this->planificar();

        $this->assertSame(0, SocialPostImage::withoutGlobalScopes()->whereNotNull('client_photo_id')->count());
    }

    public function test_correr_dos_veces_no_duplica_la_propuesta(): void
    {
        $this->foto(consentida: true, dias: 1);

        $primera = $this->planificar();
        $segunda = $this->planificar();

        // La idempotencia la da el indice unico de `idea_key`, no un
        // contador: un contador se desincroniza.
        $this->assertGreaterThan(0, $primera['proposed']);
        $this->assertSame(0, $segunda['proposed']);
    }

    public function test_una_foto_ya_descartada_no_se_vuelve_a_proponer(): void
    {
        $foto = $this->foto(consentida: true, dias: 1);

        $this->planificar();

        $this->publicacionDeLaFoto($foto)
            ->forceFill(['status' => SocialPost::STATUS_DISCARDED])
            ->save();

        $this->planificar();

        // Descartar es una respuesta. Reproponerla manana seria no haberla
        // escuchado.
        $this->assertSame(
            1,
            SocialPostImage::withoutGlobalScopes()->where('client_photo_id', $foto->id)->count(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Los huecos de agenda
    |--------------------------------------------------------------------------
    */

    public function test_un_dia_con_la_agenda_vacia_se_propone_como_hueco(): void
    {
        $this->makeResource($this->business, 'Maria');

        $this->planificar();

        $hueco = SocialPost::withoutGlobalScopes()
            ->where('angle', SocialPost::ANGLE_GAP)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($hueco);

        // MANANA, no hoy: hoy ya no da tiempo de aprobar, publicar y que
        // alguien alcance a escribir.
        $this->assertSame('2026-09-15', $hueco->subject_date->toDateString());
    }

    public function test_un_dia_con_pocas_horas_de_atencion_no_cuenta_como_hueco(): void
    {
        // Una hora de agenda al dia no es un hueco: es como trabaja ese
        // negocio, y anunciarlo cada dia entrena a ignorar el modulo.
        $this->makeResource($this->business, 'Maria', '09:00:00', '10:00:00');

        $this->planificar();

        $this->assertSame(
            0,
            SocialPost::withoutGlobalScopes()->where('angle', SocialPost::ANGLE_GAP)->count(),
        );
    }

    public function test_sin_equipo_no_hay_huecos_que_anunciar(): void
    {
        $this->planificar();

        $this->assertSame(
            0,
            SocialPost::withoutGlobalScopes()->where('angle', SocialPost::ANGLE_GAP)->count(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | El servicio que se dejo de vender
    |--------------------------------------------------------------------------
    */

    public function test_un_servicio_que_dejo_de_venderse_se_propone(): void
    {
        $olvidado = $this->makeService($this->business, 60, name: 'Pedicure spa');
        $this->venta($olvidado, semanas: 10);

        $this->planificar();

        $post = SocialPost::withoutGlobalScopes()
            ->where('angle', SocialPost::ANGLE_SERVICE)
            ->first();

        $this->assertNotNull($post);
        $this->assertSame($olvidado->id, $post->service_id);
    }

    public function test_un_servicio_que_se_sigue_vendiendo_no_se_propone(): void
    {
        $vivo = $this->makeService($this->business, 60, name: 'Manicure');
        $this->venta($vivo, semanas: 10);
        $this->venta($vivo, semanas: 1);

        $this->planificar();

        $this->assertSame(
            0,
            SocialPost::withoutGlobalScopes()->where('angle', SocialPost::ANGLE_SERVICE)->count(),
        );
    }

    public function test_un_servicio_que_nunca_se_vendio_no_se_anuncia(): void
    {
        /*
         * No es un servicio olvidado: es una fila del catalogo que alguien
         * creo por error, y anunciarla es hacerle publicidad a un error.
         */
        $this->makeService($this->business, 60, name: 'Nunca se vendió');

        $this->planificar();

        $this->assertSame(
            0,
            SocialPost::withoutGlobalScopes()->where('angle', SocialPost::ANGLE_SERVICE)->count(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Los frenos
    |--------------------------------------------------------------------------
    */

    public function test_la_bandeja_llena_calla_al_planificador(): void
    {
        $this->makeResource($this->business, 'Maria');
        $this->foto(consentida: true, dias: 1);

        for ($i = 0; $i < (int) config('spa.social.max_open_drafts'); $i++) {
            SocialPost::withoutGlobalScopes()->create([
                'business_id' => $this->business->id,
                'status' => SocialPost::STATUS_DRAFT,
                'source' => SocialPost::SOURCE_MANUAL,
                'angle' => SocialPost::ANGLE_FREE,
            ]);
        }

        // Doce ideas sin mirar no necesitan la trece: necesitan que alguien
        // las mire.
        $this->assertSame(0, $this->planificar()['proposed']);
    }

    public function test_las_propuestas_automaticas_que_nadie_miro_se_descartan_solas(): void
    {
        $vieja = SocialPost::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'status' => SocialPost::STATUS_DRAFT,
            'source' => SocialPost::SOURCE_AUTO,
            'angle' => SocialPost::ANGLE_FREE,
        ]);

        $vieja->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();

        $this->planificar();

        $this->assertSame(SocialPost::STATUS_DISCARDED, $vieja->fresh()->status);
    }

    public function test_lo_que_escribio_una_persona_no_se_descarta_solo(): void
    {
        // Una idea guardada en enero para el dia de la madre sigue siendo una
        // idea en abril. Hacerla desaparecer es perderle el trabajo a alguien.
        $mia = SocialPost::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'status' => SocialPost::STATUS_DRAFT,
            'source' => SocialPost::SOURCE_MANUAL,
            'angle' => SocialPost::ANGLE_FREE,
            'caption' => 'Para el día de la madre.',
        ]);

        $mia->forceFill(['updated_at' => now()->subDays(120)])->saveQuietly();

        $this->planificar();

        $this->assertSame(SocialPost::STATUS_DRAFT, $mia->fresh()->status);
    }

    public function test_sin_la_bandera_el_planificador_no_hace_nada(): void
    {
        $this->business->update([
            'feature_flags' => array_merge(BusinessFeaturePresets::full(), ['social_posts' => false]),
        ]);

        $this->makeResource($this->business, 'Maria');
        $this->foto(consentida: true, dias: 1);

        $this->assertSame(0, $this->planificar()['proposed']);
    }

    /*
    |--------------------------------------------------------------------------
    | El reloj
    |--------------------------------------------------------------------------
    */

    public function test_lo_programado_que_cumplio_su_hora_queda_listo_para_publicar(): void
    {
        $post = $this->programada(now()->subMinutes(5));

        $this->assertSame(1, $this->despachar()['ready']);
        $this->assertSame(SocialPost::STATUS_READY, $post->fresh()->status);
    }

    public function test_el_reloj_nunca_publica(): void
    {
        /*
         * LA PRUEBA MAS IMPORTANTE DEL ARCHIVO.
         *
         * "Ya que esta programada, mandala" es exactamente la linea que
         * alguien va a agregar con la mejor intencion. La vitrina del negocio
         * no es el lugar donde estrenar automatizacion: la ultima tecla la
         * toca una persona con el texto adelante.
         */
        $post = $this->programada(now()->subDay());

        $this->despachar();
        $this->despachar();

        $this->assertNotSame(SocialPost::STATUS_PUBLISHED, $post->fresh()->status);
        $this->assertNull($post->fresh()->published_at);
    }

    public function test_lo_que_todavia_no_es_su_hora_no_se_toca(): void
    {
        $post = $this->programada(now()->addDay());

        $this->assertSame(0, $this->despachar()['ready']);
        $this->assertSame(SocialPost::STATUS_SCHEDULED, $post->fresh()->status);
    }

    public function test_una_corrida_perdida_se_recupera_en_la_siguiente(): void
    {
        // Ventana abierta y no instante exacto: si el servidor estuvo caido
        // tres horas, la publicacion sale igual en la corrida siguiente.
        $post = $this->programada(now()->subHours(3));

        $this->assertSame(1, $this->despachar()['ready']);
        $this->assertSame(SocialPost::STATUS_READY, $post->fresh()->status);
    }

    public function test_una_programada_que_se_quedo_sin_foto_vuelve_a_la_bandeja_con_el_motivo(): void
    {
        $post = $this->programada(now()->subMinutes(5));
        $post->images()->delete();

        $this->assertSame(1, $this->despachar()['returned']);

        $post = $post->fresh();

        $this->assertSame(SocialPost::STATUS_DRAFT, $post->status);
        $this->assertNotNull($post->error);
    }

    /*
    |--------------------------------------------------------------------------
    | Ayudas
    |--------------------------------------------------------------------------
    */

    /** @return array{proposed: int, discarded: int} */
    private function planificar(): array
    {
        return $this->app->make(PostPlanner::class)->run($this->business->fresh());
    }

    /** @return array{ready: int, returned: int} */
    private function despachar(): array
    {
        return $this->app->make(PostDispatcher::class)->run($this->business->fresh());
    }

    private function foto(bool $consentida, int $dias): ClientPhoto
    {
        $clienta = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Clienta',
            'phone' => '+57300'.random_int(1000000, 9999999),
            'is_active' => true,
        ]);

        return ClientPhoto::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'client_id' => $clienta->id,
            'image_path' => 'negocios/'.$this->business->id.'/trabajos/'.uniqid().'.jpg',
            'taken_at' => now()->subDays($dias),
            'marketing_consent_at' => $consentida ? now()->subDays($dias) : null,
        ]);
    }

    /** Un servicio prestado hace tantas semanas. */
    private function venta(Service $service, int $semanas): AppointmentItem
    {
        $cuando = now()->subWeeks($semanas);
        $this->quienAtiende ??= $this->makeResource($this->business, 'Maria');

        $appointment = Appointment::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'location_id' => $this->business->primaryLocation()?->id,
            'client_name' => 'Clienta',
            'starts_at' => $cuando,
            'ends_at' => $cuando->copy()->addHour(),
            'status' => Appointment::STATUS_COMPLETED,
            'source' => 'panel',
        ]);

        return AppointmentItem::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'appointment_id' => $appointment->id,
            'service_id' => $service->id,
            'resource_id' => $this->quienAtiende->id,
            'starts_at' => $cuando,
            'ends_at' => $cuando->copy()->addHour(),
            'service_starts_at' => $cuando,
            'service_ends_at' => $cuando->copy()->addHour(),
            'price' => 50000,
        ]);
    }

    private function programada(\DateTimeInterface $cuando): SocialPost
    {
        $post = SocialPost::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'status' => SocialPost::STATUS_SCHEDULED,
            'source' => SocialPost::SOURCE_MANUAL,
            'angle' => SocialPost::ANGLE_FREE,
            'caption' => 'Quedan horas el jueves.',
            'scheduled_for' => $cuando,
        ]);

        SocialPostImage::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'social_post_id' => $post->id,
            'image_path' => 'negocios/'.$this->business->id.'/publicaciones/'.uniqid().'.jpg',
            'position' => 0,
        ]);

        return $post->fresh('images');
    }

    /** La publicacion que nacio de esa foto. */
    private function publicacionDeLaFoto(ClientPhoto $foto): ?SocialPost
    {
        $imagen = SocialPostImage::withoutGlobalScopes()
            ->where('client_photo_id', $foto->id)
            ->first();

        return $imagen === null
            ? null
            : SocialPost::withoutGlobalScopes()->find($imagen->social_post_id);
    }
}
