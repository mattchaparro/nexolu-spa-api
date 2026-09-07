<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\Messaging\MessageDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La bandeja de WhatsApp: leer lo que escriben y contestar a mano.
 *
 * Existe porque migrar el numero de un local que hoy trabaja en ManyChat
 * significa reemplazar TODO lo que ManyChat le da, no solo lo automatico. La
 * pieza que faltaba era esta: poder tomar una conversacion y responderla una
 * persona.
 *
 * DOS REGLAS QUE MANDAN SOBRE TODO LO DEMAS:
 *
 * 1. Si una persona contesta, el agente se calla en esa conversacion. Dos
 *    respuestas a la misma pregunta, contradiciendose delante de la clienta,
 *    es peor que ninguna.
 *
 * 2. Fuera de la ventana de 24 horas de Meta no sale texto libre. Y el
 *    intento no falla con un error claro: Meta lo acepta y no lo entrega. Por
 *    eso se corta ACA, con un mensaje que explica por que.
 */
class WhatsappInboxController
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * Las conversaciones, las que esperan respuesta primero.
     *
     * El orden no es por fecha a secas: lo que no se ha leido va arriba,
     * porque la bandeja se mira para saber a quien hay que contestar, no para
     * repasar lo que ya se resolvio.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,closed,all'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        $estado = $data['status'] ?? WhatsappConversation::STATUS_OPEN;

        $conversaciones = WhatsappConversation::query()
            ->when($estado !== 'all', fn ($q) => $q->where('status', $estado))
            ->when($data['q'] ?? null, fn ($q, $texto) => $q->where(
                fn ($sub) => $sub->where('phone', 'like', "%{$texto}%")
                    ->orWhereHas('client', fn ($c) => $c
                        ->where('name', 'like', "%{$texto}%")
                        ->orWhere('last_name', 'like', "%{$texto}%")),
            ))
            ->with(['client:id,name,last_name,phone', 'assignedUser:id,name'])
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $conversaciones->map(fn (WhatsappConversation $c) => $this->resumen($c))
                // Sin leer arriba, y dentro de eso por fecha. Se ordena en
                // memoria porque "sin leer" compara dos columnas entre si y
                // ningun indice ayuda con eso.
                ->sortByDesc(fn (array $c) => [$c['unread'] ? 1 : 0, $c['last_message_at']])
                ->values()->all(),
            'unread' => $conversaciones->filter(fn (WhatsappConversation $c) => $c->isUnread())->count(),
        ]);
    }

    /** El hilo completo, y marcarlo leido. */
    public function show(Request $request, WhatsappConversation $conversation): JsonResponse
    {
        $mensajes = $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        // Abrirla es leerla. Marcarlo con un boton aparte es un boton que
        // nadie oprime.
        $conversation->update(['read_at' => now()]);

        return response()->json([
            'conversation' => $this->resumen($conversation->fresh(['client', 'assignedUser'])),
            'messages' => $mensajes->map(fn (Message $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'kind' => $m->kind,
                'body' => $m->body,
                'status' => $m->status,
                'error' => $m->error,
                'at' => ($m->sent_at ?? $m->created_at)?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /**
     * Contestar a mano.
     *
     * Callar al agente NO es opcional ni un boton aparte: escribir ES tomar
     * la conversacion. Un relevo que hay que acordarse de activar es un
     * relevo que se olvida justo cuando importa.
     */
    public function reply(Request $request, WhatsappConversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        if (! $conversation->windowIsOpen()) {
            return response()->json([
                'message' => 'Pasaron más de 24 horas desde su último mensaje. WhatsApp solo permite '
                    .'plantillas aprobadas hasta que vuelva a escribir. Es una regla de Meta, no del sistema.',
                'window_open' => false,
            ], 422);
        }

        $conversation->pauseAgent();
        $conversation->update([
            'assigned_user_id' => $request->user()->id,
            'read_at' => now(),
            'last_message_at' => now(),
        ]);

        $mensaje = $this->dispatcher->queue(
            $conversation->business,
            Message::KIND_HUMAN,
            $conversation->phone,
            $data['body'],
            null,
            $conversation->client,
            null,
            null,
            $conversation,
        );

        if ($mensaje === null) {
            return response()->json(['message' => 'No se pudo enviar: el número no es válido.'], 422);
        }

        return response()->json([
            'message' => 'Enviado.',
            'conversation' => $this->resumen($conversation->fresh(['client', 'assignedUser'])),
        ], 201);
    }

    /**
     * Devolverle la conversacion al agente.
     *
     * Existe porque el relevo caduca solo a las dos horas, y a veces quien
     * atendio ya termino y no quiere esperar: la clienta pregunta algo simple
     * y el agente lo resuelve mejor.
     */
    public function resume(Request $request, WhatsappConversation $conversation): JsonResponse
    {
        $conversation->resumeAgent();

        return response()->json([
            'conversation' => $this->resumen($conversation->fresh(['client', 'assignedUser'])),
        ]);
    }

    /**
     * Cerrarla o reabrirla.
     *
     * Cerrar no silencia nada: si la persona escribe otra vez, el webhook la
     * reabre. Una conversacion cerrada que recibe un mensaje y sigue
     * escondida es una clienta a la que nadie contesta.
     */
    public function toggle(Request $request, WhatsappConversation $conversation): JsonResponse
    {
        $conversation->update([
            'status' => $conversation->status === WhatsappConversation::STATUS_OPEN
                ? WhatsappConversation::STATUS_CLOSED
                : WhatsappConversation::STATUS_OPEN,
            'read_at' => now(),
        ]);

        return response()->json([
            'conversation' => $this->resumen($conversation->fresh(['client', 'assignedUser'])),
        ]);
    }

    /** @return array<string, mixed> */
    private function resumen(WhatsappConversation $c): array
    {
        return [
            'id' => $c->id,
            'phone' => $c->phone,
            'client' => $c->client === null ? null : [
                'id' => $c->client->id,
                'name' => trim($c->client->name.' '.($c->client->last_name ?? '')),
            ],
            'status' => $c->status,
            'unread' => $c->isUnread(),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            /*
             * La pantalla necesita las tres cosas por separado: si se puede
             * escribir, hasta cuando, y quien manda ahora mismo. Un solo
             * booleano obligaria a adivinar el motivo del bloqueo.
             */
            'window_open' => $c->windowIsOpen(),
            'window_closes_at' => $c->windowClosesAt()?->toIso8601String(),
            'agent_paused' => $c->agentIsPaused(),
            'agent_resumes_at' => $c->agentIsPaused() ? $c->agent_paused_until?->toIso8601String() : null,
            'assigned_to' => $c->assignedUser?->name,
        ];
    }
}
