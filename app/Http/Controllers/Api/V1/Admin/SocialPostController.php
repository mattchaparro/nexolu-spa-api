<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\ClientPhoto;
use App\Models\Service;
use App\Models\SocialPost;
use App\Services\Social\PostComposer;
use App\Services\Social\PostPlanner;
use App\Support\ImageStorage;
use App\Support\LocationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El calendario de publicaciones del negocio.
 *
 * La pantalla tiene dos mitades y esta clase existe para servir a las dos:
 * la BANDEJA -- ideas que el sistema propuso y nadie ha mirado -- y el
 * CALENDARIO -- lo que ya tiene fecha. Mover algo de la primera a la segunda
 * es aprobarlo, y es el unico momento en que una persona decide.
 *
 * Lo que NO hay aca es un boton de "publicar por mi". Ver PostDispatcher para
 * por que, que no es pereza sino la lectura de que la vitrina del negocio no
 * es el lugar donde estrenar automatizacion.
 */
class SocialPostController
{
    /**
     * La bandeja y el calendario, de una.
     *
     * Van juntos en una sola respuesta porque en pantalla van juntos: separar
     * la llamada obligaria a la vista a coordinar dos cargas para pintar algo
     * que se lee como una sola cosa.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        try {
            $sedes = LocationScope::for($request->user())->filterFor($data['location_id'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $from = CarbonImmutable::parse($data['from'] ?? now($tz)->subDays(30), $tz)->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? now($tz)->addDays(30), $tz)->endOfDay();

        $base = fn () => SocialPost::query()
            ->with(['service:id,name', 'clientPhoto:id,image_path', 'location:id,name', 'approvedBy:id,name'])
            /*
             * Sin sede entra siempre: una publicacion que habla del negocio
             * entero es de todos. Mismo criterio que los mensajes, los gastos
             * generales y la lista de espera.
             */
            ->when($sedes !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->whereIn('location_id', $sedes)->orWhereNull('location_id'),
            ));

        // La bandeja no se filtra por fecha: una propuesta NO TIENE fecha
        // todavia, y filtrarla por una la esconderia entera.
        $tray = $base()
            ->where('status', SocialPost::STATUS_DRAFT)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $calendar = $base()
            ->whereIn('status', [
                SocialPost::STATUS_SCHEDULED,
                SocialPost::STATUS_READY,
                SocialPost::STATUS_PUBLISHED,
            ])
            ->where(fn ($q) => $q
                ->whereBetween('scheduled_for', [$from, $to])
                ->orWhereBetween('published_at', [$from, $to]))
            ->orderBy('scheduled_for')
            ->limit(200)
            ->get();

        return response()->json([
            'tray' => $tray->map(fn (SocialPost $p) => $this->detail($p, $tz))->values(),
            'calendar' => $calendar->map(fn (SocialPost $p) => $this->detail($p, $tz))->values(),
            'counts' => [
                'tray' => $tray->count(),
                // El numero que importa: lo que ya paso su hora y sigue sin
                // salir. Es el unico atraso que el modulo puede tener.
                'ready' => $base()->where('status', SocialPost::STATUS_READY)->count(),
            ],
            // El catalogo vive aca para que la pantalla no lo duplique.
            'angles' => collect(SocialPost::angleLabels())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    /**
     * Busca ideas ahora, sin esperar a la corrida de la noche.
     *
     * Existe para el negocio que abre la pantalla un domingo y la encuentra
     * vacia. Es idempotente -- el planificador dedupe por `idea_key` -- asi
     * que apretarlo cinco veces no llena nada.
     */
    public function plan(Request $request, PostPlanner $planner): JsonResponse
    {
        $result = $planner->run($request->user()->business);

        return response()->json([
            'proposed' => $result['proposed'],
            'message' => $result['proposed'] === 0
                ? 'Por ahora no hay nada nuevo que contar.'
                : 'Se agregaron '.$result['proposed'].' ideas a la bandeja.',
        ]);
    }

    /** Una publicacion que se le ocurrio a una persona. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $post = new SocialPost([
            'business_id' => $request->user()->business_id,
            'status' => SocialPost::STATUS_DRAFT,
            'source' => SocialPost::SOURCE_MANUAL,
            'created_by_user_id' => $request->user()->id,
        ]);

        return $this->apply($request, $post, $data, 201);
    }

    /**
     * POST y no PUT: el formulario manda multipart por la imagen, y PHP no
     * puebla $_FILES en un PUT. Mismo motivo que en el catalogo.
     */
    public function update(Request $request, SocialPost $post): JsonResponse
    {
        $this->authorizePost($request, $post);

        if (! $post->isEditable()) {
            return response()->json([
                'message' => 'Una publicación que ya salió no se reescribe.',
            ], 422);
        }

        return $this->apply($request, $post, $this->validated($request));
    }

    /**
     * "Escríbeme el texto."
     *
     * Se pide EXPLICITAMENTE y una publicacion a la vez. Redactar las cinco
     * propuestas de cada dia en cuanto nacen seria pagarle al IA Core por
     * ciento cincuenta textos al mes para usar diez.
     */
    public function compose(Request $request, SocialPost $post, PostComposer $composer): JsonResponse
    {
        $this->authorizePost($request, $post);

        $data = $request->validate([
            // Lo que quiera agregar quien lo pide: "menciona que es por el
            // dia de la madre".
            'extra' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $post->isEditable()) {
            return response()->json(['message' => 'Esta publicación ya no se edita.'], 422);
        }

        $written = $composer->write($post->load(['service', 'location']), $request->user(), $data['extra'] ?? null);

        if ($written === null) {
            /*
             * El asistente no contesto. NO es un error del modulo: se dice
             * que no se pudo y el negocio escribe su texto a mano, que es
             * exactamente lo que hacia antes de que esto existiera.
             */
            return response()->json([
                'message' => 'El asistente no está disponible ahora. Puedes escribir el texto a mano.',
            ], 422);
        }

        $post->forceFill([
            'caption' => $written['caption'],
            'hashtags' => $written['hashtags'],
            'composed_at' => now(),
        ])->save();

        return response()->json(['post' => $this->detail($post->fresh($this->relations()), $this->tz($request))]);
    }

    /**
     * Aprobar: ponerle fecha y sacarla de la bandeja.
     *
     * Es el momento en que una persona se hace responsable del texto. Por eso
     * se exige que este COMPLETA: aprobar algo sin foto es programar un
     * problema para el jueves.
     */
    public function schedule(Request $request, SocialPost $post): JsonResponse
    {
        $this->authorizePost($request, $post);

        $data = $request->validate([
            'scheduled_for' => ['required', 'date'],
        ]);

        if (! $post->isEditable()) {
            return response()->json(['message' => 'Esta publicación ya no se programa.'], 422);
        }

        if (! $post->isComplete()) {
            return response()->json([
                'message' => 'Le falta el texto o la imagen.',
            ], 422);
        }

        $post->forceFill([
            'status' => SocialPost::STATUS_SCHEDULED,
            'scheduled_for' => CarbonImmutable::parse($data['scheduled_for'], $this->tz($request)),
            'approved_by_user_id' => $request->user()->id,
            'error' => null,
        ])->save();

        return response()->json(['post' => $this->detail($post->fresh($this->relations()), $this->tz($request))]);
    }

    /**
     * "Ya la publiqué."
     *
     * Lo marca la persona que la pego en Instagram. Es un dato que el sistema
     * no puede saber solo, y sin el el calendario deja de servir a la semana:
     * todo se ve como pendiente y nadie distingue lo que salio de lo que no.
     */
    public function markPublished(Request $request, SocialPost $post): JsonResponse
    {
        $this->authorizePost($request, $post);

        if ($post->status === SocialPost::STATUS_PUBLISHED) {
            return response()->json(['post' => $this->detail($post, $this->tz($request))]);
        }

        if (! $post->isComplete()) {
            return response()->json(['message' => 'Le falta el texto o la imagen.'], 422);
        }

        $post->forceFill([
            'status' => SocialPost::STATUS_PUBLISHED,
            'published_at' => now(),
            'error' => null,
        ])->save();

        return response()->json(['post' => $this->detail($post->fresh($this->relations()), $this->tz($request))]);
    }

    /**
     * "Esta no va."
     *
     * Se descarta, no se borra. Que una idea se haya descartado es la razon
     * por la que el planificador no la vuelve a proponer -- y borrarla haria
     * justo eso, reproponerla el dia siguiente.
     */
    public function discard(Request $request, SocialPost $post): JsonResponse
    {
        $this->authorizePost($request, $post);

        if ($post->status === SocialPost::STATUS_PUBLISHED) {
            return response()->json([
                'message' => 'Una publicación que ya salió no se descarta.',
            ], 422);
        }

        $post->forceFill(['status' => SocialPost::STATUS_DISCARDED])->save();

        return response()->json(['message' => 'Descartada.']);
    }

    /**
     * Las fotos que SE PUEDEN publicar.
     *
     * Este listado es la unica puerta por la que una foto de la ficha llega a
     * una publicacion, y filtra por consentimiento. No hay parametro para
     * pedir "todas": una funcion que se puede saltar con un parametro no es
     * una salvaguarda.
     */
    public function photoPool(Request $request): JsonResponse
    {
        $tz = $this->tz($request);

        $photos = ClientPhoto::whereNotNull('marketing_consent_at')
            ->with('appointmentItem.service:id,name')
            ->orderByDesc('taken_at')
            ->limit(60)
            ->get();

        return response()->json([
            'photos' => $photos->map(fn (ClientPhoto $p) => [
                'id' => $p->id,
                'url' => ImageStorage::url($p->image_path),
                'date' => $p->taken_at?->setTimezone($tz)->format('d/m/Y'),
                'service_name' => $p->appointmentItem?->service?->name,
                // Sin nombre de clienta a proposito: quien arma la
                // publicacion no necesita saber de quien son esas manos, y no
                // dar el dato es mas barato que confiar en que no se use.
            ])->values(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apply(Request $request, SocialPost $post, array $data, int $status = 200): JsonResponse
    {
        $business = $request->user()->business;

        if (array_key_exists('client_photo_id', $data)) {
            $photoId = $this->consentedPhotoId($data['client_photo_id']);

            if ($data['client_photo_id'] !== null && $photoId === null) {
                /*
                 * O no es de este negocio, o no tiene permiso. Se contesta lo
                 * mismo en los dos casos: decir "esa foto existe pero no
                 * puedes" ya es contar algo de otro negocio.
                 */
                return response()->json([
                    'message' => 'Esa foto no se puede publicar. Falta el permiso de la clienta.',
                ], 422);
            }

            $post->client_photo_id = $photoId;
        }

        if (array_key_exists('service_id', $data)) {
            $post->service_id = $data['service_id'] === null
                ? null
                : Service::where('business_id', $business->id)->where('id', $data['service_id'])->value('id');
        }

        if (array_key_exists('location_id', $data)) {
            $locationId = $data['location_id'] === null ? null : (int) $data['location_id'];

            abort_unless(LocationScope::for($request->user())->allows($locationId), 404);

            $post->location_id = $locationId;
        }

        foreach (['angle', 'caption', 'subject_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $post->{$field} = $data[$field];
            }
        }

        if (array_key_exists('hashtags', $data)) {
            $post->hashtags = $this->normalizeHashtags($data['hashtags']);
        }

        if ($request->hasFile('image')) {
            // La anterior se borra: una imagen reemplazada que sigue en el
            // bucket es basura que nadie va a ir a buscar.
            ImageStorage::delete($post->image_path);
            $post->image_path = ImageStorage::store($request->file('image'), $business->id, 'publicaciones');
        }

        /*
         * Si la escribio una persona, deja de ser texto del modelo. Importa
         * para la pantalla: "lo escribió el asistente" al lado de un texto
         * que la duena reescribio entero es una etiqueta que miente.
         */
        if (array_key_exists('caption', $data)) {
            $post->composed_at = null;
        }

        $post->save();

        return response()->json(
            ['post' => $this->detail($post->fresh($this->relations()), $this->tz($request))],
            $status,
        );
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'angle' => ['nullable', 'string', Rule::in(SocialPost::angles())],
            'caption' => ['nullable', 'string', 'max:2200'],
            'hashtags' => ['nullable', 'array', 'max:30'],
            'hashtags.*' => ['string', 'max:60'],
            'service_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'client_photo_id' => ['nullable', 'integer'],
            'subject_date' => ['nullable', 'date'],
            'image' => ImageStorage::rules(),
        ]);
    }

    /**
     * El id de la foto SOLO si es de este negocio y tiene consentimiento.
     *
     * El scope global de `ClientPhoto` ya limita al negocio; el
     * `whereNotNull` es lo que agrega este modulo. Van juntos en un solo
     * lugar para que no exista una segunda forma de resolver una foto.
     */
    private function consentedPhotoId(mixed $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return ClientPhoto::where('id', $id)
            ->whereNotNull('marketing_consent_at')
            ->value('id');
    }

    /**
     * @param  array<int, string>|null  $tags
     * @return list<string>|null
     */
    private function normalizeHashtags(?array $tags): ?array
    {
        if ($tags === null) {
            return null;
        }

        $clean = [];

        foreach ($tags as $tag) {
            // Se guardan CON almohadilla y sin espacios. Que la pantalla
            // mande "#unas" o "unas" no deberia producir dos etiquetas
            // distintas en la base.
            $tag = '#'.ltrim(preg_replace('/\s+/u', '', trim($tag)) ?? '', '#');

            if ($tag !== '#') {
                $clean[] = $tag;
            }
        }

        return array_slice(array_values(array_unique($clean)), 0, 30);
    }

    /**
     * La publicacion de la sede ajena no existe para quien no la ve.
     *
     * Sin esto el listado la esconde pero el id directo la edita igual --
     * mismo guardia que la lista de espera y los mensajes.
     */
    private function authorizePost(Request $request, SocialPost $post): void
    {
        abort_unless(LocationScope::for($request->user())->allows($post->location_id), 404);
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['service:id,name', 'clientPhoto:id,image_path', 'location:id,name', 'approvedBy:id,name'];
    }

    private function tz(Request $request): string
    {
        return $request->user()->business->businessTimezone();
    }

    /** @return array<string, mixed> */
    private function detail(SocialPost $post, string $tz): array
    {
        return [
            'id' => $post->id,
            'status' => $post->status,
            'status_label' => SocialPost::statusLabels()[$post->status] ?? $post->status,
            'angle' => $post->angle,
            'angle_label' => SocialPost::angleLabels()[$post->angle] ?? $post->angle,
            'source' => $post->source,
            'headline' => $this->headline($post),
            'caption' => $post->caption,
            'hashtags' => $post->hashtags ?? [],
            // Escrito por el asistente y todavia sin tocar por nadie.
            'written_by_assistant' => $post->composed_at !== null,
            'image_url' => ImageStorage::url($post->image_path ?: $post->clientPhoto?->image_path),
            'from_client_photo' => $post->client_photo_id !== null,
            'service_name' => $post->service?->name,
            'location_name' => $post->location?->name,
            'subject_date' => $post->subject_date?->toDateString(),
            'scheduled_for' => $post->scheduled_for?->setTimezone($tz)->toIso8601String(),
            'published_at' => $post->published_at?->setTimezone($tz)->toIso8601String(),
            'approved_by' => $post->approvedBy?->name,
            'is_complete' => $post->isComplete(),
            'error' => $post->error,
        ];
    }

    /**
     * De que se trata, en una linea.
     *
     * Se calcula y no se guarda: una propuesta nace sin texto -- el redactor
     * se llama despues, y solo si alguien quiere -- y una tarjeta que dice
     * "(sin titulo)" no invita a mirarla. Esto es lo que hace que la bandeja
     * se lea de un vistazo.
     */
    private function headline(SocialPost $post): string
    {
        return match ($post->angle) {
            SocialPost::ANGLE_WORK => 'Un trabajo recién hecho'
                .($post->service?->name ? ': '.$post->service->name : ''),

            SocialPost::ANGLE_GAP => $post->subject_date === null
                ? 'Quedan horas libres'
                : 'Horas libres el '.$post->subject_date->locale('es')->isoFormat('dddd D [de] MMMM'),

            SocialPost::ANGLE_SERVICE => 'Recordar que existe'
                .($post->service?->name ? ' '.$post->service->name : ' un servicio'),

            SocialPost::ANGLE_TEAM => 'Presentar al equipo',

            default => 'Publicación',
        };
    }
}
