<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\InstagramStory;
use App\Services\Instagram\StoryPublisher;
use App\Support\ImageStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historias de Instagram desde el panel.
 *
 * Lo que la API de Meta permite en una historia es poco: la imagen y
 * menciones. Nada de stickers, enlaces, encuestas, ubicacion, musica ni
 * texto encima. Se dice en la pantalla para que nadie suba una foto
 * esperando ponerle un "Reserva aqui" que no se puede.
 */
class InstagramStoryController
{
    public function __construct(private readonly StoryPublisher $publisher) {}

    public function index(Request $request): JsonResponse
    {
        $tz = $request->user()->business->businessTimezone();

        $historias = InstagramStory::orderByDesc('created_at')->limit(50)->get();

        return response()->json([
            'data' => $historias->map(fn (InstagramStory $h) => $this->payload($h, $tz)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        $data = $request->validate([
            /*
             * JPEG y nada mas. No es una preferencia: Meta solo acepta JPEG
             * para publicar imagenes, y rechaza el resto. Aceptar un PNG
             * aqui seria dejar que alguien programe una historia para el
             * sabado que va a fallar el sabado.
             */
            'image' => ['required', 'file', 'mimes:jpg,jpeg', 'max:8192'],
            'mentions' => ['nullable', 'array', 'max:5'],
            'mentions.*' => ['string', 'max:60'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $cuando = null;

        if (! empty($data['scheduled_at'])) {
            $cuando = CarbonImmutable::parse($data['scheduled_at'], $tz);

            if ($cuando->isPast()) {
                return response()->json(['message' => 'Esa fecha y hora ya pasaron.'], 422);
            }
        }

        $historia = InstagramStory::create([
            'business_id' => $business->id,
            'image_path' => ImageStorage::store($request->file('image'), $business->id, 'stories'),
            'mentions' => array_map(fn (string $m) => ltrim($m, '@'), $data['mentions'] ?? []),
            'scheduled_at' => $cuando?->utc(),
            'status' => $cuando === null
                ? InstagramStory::STATUS_DRAFT
                : InstagramStory::STATUS_SCHEDULED,
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($historia, $tz)], 201);
    }

    /** Publicarla ya, sin esperar a la hora programada. */
    public function publish(Request $request, InstagramStory $story): JsonResponse
    {
        if (! $story->isEditable()) {
            return response()->json(['message' => 'Esta historia ya se publicó.'], 422);
        }

        $ok = $this->publisher->publish($story);
        $fresca = $story->fresh();

        return response()->json([
            'message' => $ok
                ? '¡Listo! La historia quedó publicada.'
                : ($fresca->error ?? 'No se pudo publicar.'),
            'data' => $this->payload($fresca, $request->user()->business->businessTimezone()),
        ], $ok ? 200 : 422);
    }

    public function cancel(Request $request, InstagramStory $story): JsonResponse
    {
        if (! $story->isEditable()) {
            return response()->json(['message' => 'Esta historia ya se publicó.'], 422);
        }

        $story->update(['status' => InstagramStory::STATUS_CANCELLED]);

        return response()->json(['message' => 'Historia cancelada.']);
    }

    /** @return array<string, mixed> */
    private function payload(InstagramStory $h, string $tz): array
    {
        return [
            'id' => $h->id,
            'image_url' => $h->imageUrl(),
            'mentions' => $h->mentions,
            'status' => $h->status,
            'status_label' => InstagramStory::statusLabels()[$h->status] ?? $h->status,
            'scheduled_at' => $h->scheduled_at?->setTimezone($tz)->toIso8601String(),
            'published_at' => $h->published_at?->setTimezone($tz)->toIso8601String(),
            'error' => $h->error,
            'editable' => $h->isEditable(),
        ];
    }
}
