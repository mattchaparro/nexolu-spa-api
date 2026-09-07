<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Broadcast;
use App\Services\Messaging\BroadcastService;
use App\Services\Messaging\MessageDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Difusiones: la misma promocion a muchas, ahora o el jueves a las 10.
 *
 * Lo que hoy se hace a mano desde ManyChat. Distinto de `campaigns`, que son
 * descuentos que se aplican solos al cobrar.
 */
class BroadcastController
{
    public function __construct(
        private readonly BroadcastService $broadcasts,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        $difusiones = Broadcast::with('business')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $difusiones->map(fn (Broadcast $b) => $this->payload($b, $tz)),
            'sends_by_itself' => $this->dispatcher->sendsByItself($business),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $business = $request->user()->business;

        $difusion = Broadcast::create($data + [
            'business_id' => $business->id,
            'created_by_user_id' => $request->user()->id,
            'status' => empty($data['scheduled_at'])
                ? Broadcast::STATUS_DRAFT
                : Broadcast::STATUS_SCHEDULED,
        ]);

        return response()->json([
            'data' => $this->payload($difusion, $business->businessTimezone()),
        ], 201);
    }

    public function update(Request $request, Broadcast $broadcast): JsonResponse
    {
        /*
         * Una difusion que ya empezo a salir no se edita.
         *
         * Parte de las clientas ya la recibio: cambiarla ahora seria mandar
         * dos versiones distintas de la misma promocion y no saber cual vio
         * cada quien.
         */
        if (! $broadcast->isEditable()) {
            return response()->json(['message' => 'Esta difusión ya salió y no se puede editar.'], 422);
        }

        $data = $this->validated($request);

        $broadcast->update($data + [
            'status' => empty($data['scheduled_at'])
                ? Broadcast::STATUS_DRAFT
                : Broadcast::STATUS_SCHEDULED,
        ]);

        return response()->json([
            'data' => $this->payload($broadcast->fresh(), $request->user()->business->businessTimezone()),
        ]);
    }

    /**
     * A cuantas les llegaria, ahora mismo.
     *
     * Existe porque mandar una difusion no se puede deshacer: ver el numero
     * ANTES es la unica oportunidad de darse cuenta de que el filtro estaba
     * mal.
     */
    public function preview(Request $request, Broadcast $broadcast): JsonResponse
    {
        $muestra = $this->broadcasts->audienceQuery($broadcast)
            ->limit(5)
            ->get(['id', 'name', 'last_name', 'phone']);

        return response()->json([
            'count' => $this->broadcasts->audienceCount($broadcast),
            'sample' => $muestra->map(fn ($c) => ['nombre' => $c->fullName(), 'telefono' => $c->phone]),
        ]);
    }

    /** Mandarla ya, sin esperar a la hora programada. */
    public function send(Request $request, Broadcast $broadcast): JsonResponse
    {
        if (! $broadcast->isEditable()) {
            return response()->json(['message' => 'Esta difusión ya salió.'], 422);
        }

        $enviados = $this->broadcasts->dispatch($broadcast);

        return response()->json([
            'message' => $enviados === 0
                ? 'No hay nadie que cumpla los filtros y acepte promociones.'
                : "Listo: {$enviados} mensajes preparados.",
            'recipients' => $enviados,
        ]);
    }

    public function cancel(Request $request, Broadcast $broadcast): JsonResponse
    {
        if (! $broadcast->isEditable()) {
            return response()->json(['message' => 'Esta difusión ya salió.'], 422);
        }

        $broadcast->update(['status' => Broadcast::STATUS_CANCELLED]);

        return response()->json(['message' => 'Difusión cancelada.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'template_name' => ['required', 'string', 'max:128'],
            'template_language' => ['nullable', 'string', 'max:12'],
            'template_params' => ['nullable', 'array', 'max:10'],
            'template_params.*' => ['string', 'max:255'],
            'body_template' => ['required', 'string', 'max:2000'],
            'scheduled_at' => ['nullable', 'date'],
            'audience' => ['nullable', 'array'],
            'audience.location_id' => ['nullable', 'integer'],
            'audience.visited_since' => ['nullable', 'date_format:Y-m-d'],
            'audience.not_visited_since' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (! empty($data['scheduled_at'])) {
            $cuando = CarbonImmutable::parse($data['scheduled_at'], $tz);

            // Programar para el pasado es un error de dedo, y el comando la
            // mandaria en la corrida siguiente sin avisar.
            if ($cuando->isPast()) {
                abort(422, 'Esa fecha y hora ya pasaron.');
            }

            $data['scheduled_at'] = $cuando->utc();
        }

        $data['template_language'] ??= 'es';

        return $data;
    }

    /** @return array<string, mixed> */
    private function payload(Broadcast $b, string $tz): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'status' => $b->status,
            'status_label' => Broadcast::statusLabels()[$b->status] ?? $b->status,
            'template_name' => $b->template_name,
            'template_language' => $b->template_language,
            'template_params' => $b->template_params,
            'body_template' => $b->body_template,
            'audience' => $b->audience,
            'scheduled_at' => $b->scheduled_at?->setTimezone($tz)->toIso8601String(),
            'sent_at' => $b->sent_at?->setTimezone($tz)->toIso8601String(),
            'recipients' => $b->recipients,
            'editable' => $b->isEditable(),
        ];
    }
}
