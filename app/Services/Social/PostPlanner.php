<?php

namespace App\Services\Social;

use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\ClientPhoto;
use App\Models\Resource;
use App\Models\Service;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Llena la hoja en blanco.
 *
 * Es la razon por la que este modulo vive dentro de la API de agenda y no en
 * una herramienta suelta. Un programador de publicaciones cualquiera abre un
 * calendario vacio y pregunta "que quieres publicar hoy" -- que es
 * exactamente la pregunta que el negocio no sabe contestar a las once de la
 * noche. Este planificador la contesta con lo que ya paso:
 *
 *   - ayer salio una clienta feliz y hay foto CON PERMISO,
 *   - el jueves hay cuatro horas muertas,
 *   - el pedicure spa no se vende hace mes y medio.
 *
 * Las tres son noticias, y ninguna requiere que a nadie se le ocurra nada.
 *
 * PROPONE, NO PUBLICA, Y NI SIQUIERA ESCRIBE. Lo que deja es una propuesta en
 * `draft` con su angulo y su material: la foto, el servicio, el dia. El texto
 * se escribe cuando alguien decide que esa propuesta vale (ver PostComposer),
 * y no antes: redactar las cinco de cada dia seria pagarle al IA Core por
 * ciento cincuenta textos al mes de los que se usan diez.
 *
 * ES IDEMPOTENTE POR `idea_key`, no por una bandera de "ya propuesto". Corre
 * a diario sobre una ventana abierta, asi que sin eso volveria a proponer el
 * hueco del jueves cada dia hasta que llegue el jueves. Misma leccion que los
 * recordatorios: una restriccion de la base no se desincroniza, un contador
 * si.
 */
class PostPlanner
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @return array{proposed: int, discarded: int}
     */
    public function run(Business $business, ?CarbonImmutable $now = null): array
    {
        if (! $business->hasFeature('social_posts')) {
            return ['proposed' => 0, 'discarded' => 0];
        }

        $tz = $business->businessTimezone();
        $now ??= CarbonImmutable::now($tz);

        $discarded = $this->discardStale($business, $now);

        /*
         * El presupuesto del dia.
         *
         * Una bandeja con doce propuestas sin mirar no necesita la trece:
         * necesita que alguien la mire. Cuando se llena, el planificador se
         * calla en vez de seguir empujando -- que es como una funcion que
         * ayuda se convierte en una que se ignora.
         */
        $budget = (int) config('spa.social.max_open_drafts') - $this->openDrafts($business);

        if ($budget <= 0) {
            return ['proposed' => 0, 'discarded' => $discarded];
        }

        $proposed = 0;

        foreach ([
            fn (int $left) => $this->fromWorkPhotos($business, $now, $left),
            fn (int $left) => $this->fromEmptyDays($business, $now, $left),
            fn (int $left) => $this->fromQuietServices($business, $now, $left),
        ] as $source) {
            if ($budget <= 0) {
                break;
            }

            $made = $source($budget);
            $proposed += $made;
            $budget -= $made;
        }

        return ['proposed' => $proposed, 'discarded' => $discarded];
    }

    /**
     * "Miren lo que hicimos ayer."
     *
     * La foto tiene que tener CONSENTIMIENTO. No es un filtro mas entre
     * varios: es la unica razon por la que este metodo puede existir. Las
     * fotos de la ficha se tomaron para que la profesional se acuerde del
     * color, no para el Instagram del local, y que se vean muy bien no
     * cambia de quien son.
     */
    private function fromWorkPhotos(Business $business, CarbonImmutable $now, int $budget): int
    {
        $limit = min($budget, (int) config('spa.social.work_photos_per_run'));

        if ($limit <= 0) {
            return 0;
        }

        $photos = ClientPhoto::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->whereNotNull('marketing_consent_at')
            ->where('taken_at', '>=', $now->subDays((int) config('spa.social.work_photo_days'))->utc())
            // Ya usada antes -- en una propuesta aprobada, publicada o
            // descartada -- no vuelve. Descartar una foto es una respuesta, y
            // reproponerla seria no haberla escuchado.
            ->whereNotIn('id', SocialPostImage::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->whereNotNull('client_photo_id')
                ->select('client_photo_id'))
            ->with('appointmentItem:id,appointment_id,service_id')
            ->orderByDesc('taken_at')
            ->limit($limit)
            ->get();

        $made = 0;

        foreach ($photos as $photo) {
            $made += $this->propose(
                $business,
                [
                    'idea_key' => 'foto:'.$photo->id,
                    'angle' => SocialPost::ANGLE_WORK,
                    'service_id' => $photo->appointmentItem?->service_id,
                ],
                $photo->id,
            );
        }

        return $made;
    }

    /**
     * "El jueves tenemos cupo."
     *
     * Es la publicacion que solo puede escribir quien tiene la agenda
     * adelante, y la unica de las tres que gana o pierde plata el mismo dia:
     * una hora que no se vendio no se recupera nunca.
     *
     * Se mira desde MANANA. Hoy no alcanza -- entre que alguien aprueba,
     * publica y alguien mas lo ve y escribe, el dia se acabo -- y proponer
     * algo que ya no da tiempo entrena al negocio a ignorar el modulo.
     */
    private function fromEmptyDays(Business $business, CarbonImmutable $now, int $budget): int
    {
        $limit = min($budget, (int) config('spa.social.gaps_per_run'));

        if ($limit <= 0) {
            return 0;
        }

        $tz = $business->businessTimezone();
        $threshold = (int) config('spa.social.gap_min_free_min');
        $horizon = (int) config('spa.social.gap_horizon_days');

        $resources = Resource::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->get();

        if ($resources->isEmpty()) {
            return 0;
        }

        $made = 0;

        for ($day = 1; $day <= $horizon && $made < $limit; $day++) {
            $date = $now->addDays($day)->startOfDay();

            /** @var array<int|string, int> $freeByLocation */
            $freeByLocation = [];

            foreach ($resources as $resource) {
                $free = 0;

                foreach ($this->availability->freeWindowsFor($business, $resource, $date, $tz) as $window) {
                    $free += $window->durationMinutes();
                }

                // La sede va como llave del arreglo: el hueco de Cedritos se
                // anuncia como el de Cedritos, no como "hay cupo" a secas
                // -- que manda gente al local equivocado.
                $key = $resource->location_id ?? 0;
                $freeByLocation[$key] = ($freeByLocation[$key] ?? 0) + $free;
            }

            foreach ($freeByLocation as $locationId => $free) {
                if ($free < $threshold || $made >= $limit) {
                    continue;
                }

                $made += $this->propose($business, [
                    'idea_key' => 'hueco:'.$locationId.':'.$date->toDateString(),
                    'angle' => SocialPost::ANGLE_GAP,
                    'location_id' => $locationId ?: null,
                    /*
                     * El dia del hueco, no el de la publicacion: anunciar el
                     * jueves sirve el lunes. Cuando sale es decision de quien
                     * aprueba, y por eso `scheduled_for` sigue en nulo.
                     */
                    'subject_date' => $date->toDateString(),
                ]);
            }
        }

        return $made;
    }

    /**
     * "Nos acordamos de que existe."
     *
     * Se exige que el servicio SE HAYA VENDIDO alguna vez. Uno que nunca se
     * vendio no es un servicio olvidado: es una fila del catalogo que alguien
     * creo por error, y anunciarla es hacer publicidad de un error.
     */
    private function fromQuietServices(Business $business, CarbonImmutable $now, int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }

        /*
         * En UTC al comparar: las columnas se guardan asi y `$now` viene en
         * la zona del negocio. Sin esto el corte se corre cinco horas, que en
         * un umbral de seis semanas no se nota nunca -- hasta el dia que si.
         */
        $since = $now->subWeeks((int) config('spa.social.service_quiet_weeks'));

        $sold = AppointmentItem::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('service_starts_at', '>=', $since->utc())
            ->select('service_id');

        $service = Service::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->whereNotIn('id', $sold)
            ->whereIn('id', AppointmentItem::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->select('service_id'))
            ->orderByDesc('price')
            ->first();

        if ($service === null) {
            return 0;
        }

        return $this->propose($business, [
            // Por semana ISO: si el servicio sigue sin venderse, vuelve a
            // proponerse el lunes siguiente y no todos los dias.
            'idea_key' => 'servicio:'.$service->id.':'.$now->isoFormat('GGGG-[W]WW'),
            'angle' => SocialPost::ANGLE_SERVICE,
            'service_id' => $service->id,
        ]);
    }

    /**
     * Deja la propuesta, o no hace nada si ya estaba.
     *
     * @param  array<string, mixed>  $attributes
     * @param  int|null  $photoId  La foto de la ficha con que nace, si la hay.
     * @return int 1 si se creo, 0 si ya existia
     */
    private function propose(Business $business, array $attributes, ?int $photoId = null): int
    {
        try {
            $post = SocialPost::withoutGlobalScopes()->create($attributes + [
                'business_id' => $business->id,
                'status' => SocialPost::STATUS_DRAFT,
                'source' => SocialPost::SOURCE_AUTO,
            ]);

            if ($photoId !== null) {
                SocialPostImage::withoutGlobalScopes()->create([
                    'business_id' => $business->id,
                    'social_post_id' => $post->id,
                    'client_photo_id' => $photoId,
                    'position' => 0,
                ]);
            }
        } catch (UniqueConstraintViolationException) {
            // Ya se propuso esta idea. Es el caso NORMAL: el planificador
            // corre todos los dias sobre la misma ventana de fechas.
            return 0;
        }

        return 1;
    }

    /**
     * Barre las propuestas automaticas que nadie miro.
     *
     * Solo las automaticas. Lo que escribio una persona no se descarta solo
     * -- una idea guardada en enero para el dia de la madre sigue siendo una
     * idea en abril, y hacerla desaparecer es perderle el trabajo a alguien.
     */
    private function discardStale(Business $business, CarbonImmutable $now): int
    {
        return SocialPost::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('status', SocialPost::STATUS_DRAFT)
            ->where('source', SocialPost::SOURCE_AUTO)
            ->where('updated_at', '<', $now->subDays((int) config('spa.social.draft_stale_days'))->utc())
            ->update(['status' => SocialPost::STATUS_DISCARDED]);
    }

    private function openDrafts(Business $business): int
    {
        return SocialPost::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('status', SocialPost::STATUS_DRAFT)
            ->count();
    }
}
