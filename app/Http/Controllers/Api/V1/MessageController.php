<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Message;
use App\Services\Messaging\MessageDispatcher;
use App\Support\LocationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La bandeja de salida: lo que el sistema quiso mandar.
 *
 * Existe sobre todo por el MODO MANUAL, que es como opera un negocio mientras
 * no tenga un numero de WhatsApp aprobado -- y como van a querer seguir
 * operando algunos. Sin esta pantalla, un aviso que el sistema preparo no lo ve
 * nadie: se pierde igual que antes, solo que ahora en una tabla.
 *
 * Tambien es donde se ve lo que FALLO. Un mensaje fallido que no se puede
 * mirar ni reintentar es un cliente que no se entero de nada y un negocio que
 * cree que si.
 */
class MessageController
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:24'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $business = $request->user()->business;

        try {
            $sedes = LocationScope::for($request->user())->filterFor($data['location_id'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $messages = Message::with(['client', 'appointment', 'location'])
            /*
             * Lo SIN sede entra siempre: un mensaje suelto no es de ningun
             * local y esconderlo de todo el mundo seria hacerlo desaparecer.
             * Mismo criterio que los gastos del negocio entero.
             */
            ->when($sedes !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->whereIn('location_id', $sedes)->orWhereNull('location_id'),
            ))
            ->when(
                ! empty($data['status']),
                fn ($q) => $q->where('status', $data['status']),
                // Sin filtro: lo que falta por hacer. Es a lo que se entra.
                fn ($q) => $q->whereIn('status', [Message::STATUS_MANUAL, Message::STATUS_FAILED]),
            )
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $tz = $business->businessTimezone();

        return response()->json([
            'data' => $messages->map(fn (Message $m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'status' => $m->status,
                'status_label' => Message::statusLabels()[$m->status] ?? $m->status,
                'to' => $m->to,
                'client_name' => $m->client?->fullName() ?? $m->appointment?->client_name,
                'body' => $m->body,
                'location' => $m->location?->name,
                'error' => $m->error,
                'created_at' => $m->created_at?->setTimezone($tz)->toIso8601String(),
                'sent_at' => $m->sent_at?->setTimezone($tz)->toIso8601String(),
                /*
                 * El enlace de WhatsApp con el mensaje ya escrito.
                 *
                 * Es el atajo que hace usable el modo manual: un toque y sale
                 * el chat con esa persona y el texto adentro, sin copiar,
                 * pegar ni buscar el contacto. Se arma aca porque el numero ya
                 * viene normalizado.
                 */
                'whatsapp_url' => 'https://wa.me/'.preg_replace('/\D/', '', $m->to)
                    .'?text='.rawurlencode($m->body),
            ]),
            'pending' => Message::whereIn('status', [Message::STATUS_MANUAL, Message::STATUS_FAILED])->count(),
            'mode' => $business->messaging_mode,
            'sends_by_itself' => $this->dispatcher->sendsByItself($business),
        ]);
    }

    /**
     * "Ya lo mandé."
     *
     * Es el cierre del modo manual: sin esto la lista crece para siempre y deja
     * de servir, porque nadie distingue lo que falta de lo que ya se hizo.
     */
    public function markSent(Request $request, Message $message): JsonResponse
    {
        abort_unless(LocationScope::for($request->user())->allows($message->location_id), 404);

        return response()->json([
            'message' => 'Marcado como enviado.',
            'data' => ['id' => $this->dispatcher->markSentByHand($message, $request->user()->id)->id],
        ]);
    }

    /** Reintentar uno que falló. Sólo tiene sentido con canal configurado. */
    public function retry(Request $request, Message $message): JsonResponse
    {
        abort_unless(LocationScope::for($request->user())->allows($message->location_id), 404);

        if (! $this->dispatcher->sendsByItself($request->user()->business)) {
            return response()->json([
                'message' => 'Este negocio envía a mano. Usa el botón de WhatsApp y márcalo como enviado.',
            ], 422);
        }

        /*
         * Directo y no por la cola, a propósito.
         *
         * Acá hay una persona mirando la pantalla que acaba de tocar
         * "reintentar": necesita saber AHORA si funcionó. Encolarlo devolvería
         * "listo" sin saber nada, y si vuelve a fallar se entera cuando vuelva
         * a mirar la lista — o nunca.
         *
         * Es al revés que el envío automático, donde nadie espera la respuesta
         * y lo que importa es no congelar el mostrador.
         */
        return response()->json(
            $this->dispatcher->send($message)
                ? ['message' => 'Enviado.']
                : ['message' => 'Volvió a fallar: '.($message->fresh()->error ?? 'el canal lo rechazó.')],
        );
    }

    /** Descartar uno que ya no tiene sentido mandar. */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        abort_unless(LocationScope::for($request->user())->allows($message->location_id), 404);

        // Se borra de verdad: un mensaje descartado no es un dato historico que
        // haya que explicar, y dejarlo en la lista con otro estado es lo mismo
        // que no descartarlo.
        $message->delete();

        return response()->json(['message' => 'Descartado.']);
    }
}
