<?php

namespace App\Services\Messaging;

use App\Models\Broadcast;
use App\Models\Client;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Quien recibe una difusion, y como sale.
 *
 * Dos reglas gobiernan todo este archivo, y ninguna es negociable:
 *
 * 1. SOLO quien acepto recibir promociones. Mandarle publicidad a quien no
 *    la pidio no es un descuido de producto: en Colombia lo regula la Ley
 *    1581, y para Meta es la via mas rapida a que le bajen la calidad al
 *    numero y despues lo bloqueen. Un numero castigado deja al negocio sin
 *    recordatorios, que es lo que de verdad le importa.
 *
 * 2. El criterio se guarda, la lista NO. Una difusion programada para el
 *    jueves tiene que alcanzar a quien se volvio clienta el miercoles, y no
 *    debe alcanzar a quien pidio que la sacaran el martes.
 */
class BroadcastService
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * A quienes les llegaria, hoy.
     *
     * @return Builder<Client>
     */
    public function audienceQuery(Broadcast $broadcast): Builder
    {
        $filtros = $broadcast->audience ?? [];

        return Client::withoutGlobalScope('business')
            ->where('business_id', $broadcast->business_id)
            ->where('is_active', true)
            ->where('accepts_marketing', true)
            ->whereNotNull('phone')
            ->when(
                ! empty($filtros['location_id']),
                fn ($q) => $q->whereHas(
                    'appointments',
                    fn ($qq) => $qq->where('location_id', $filtros['location_id']),
                ),
            )
            /*
             * "Vino hace poco" y "no viene hace mucho" son las dos campanas
             * que de verdad se mandan: premiar a la clienta fiel, o traer de
             * vuelta a la que se enfrio. Se miran las visitas COBRADAS: una
             * cita agendada y no atendida no dice nada de nadie.
             */
            ->when(
                ! empty($filtros['visited_since']),
                fn ($q) => $q->whereHas('appointments', fn ($qq) => $qq
                    ->whereNotNull('checked_out_at')
                    ->where('checked_out_at', '>=', $filtros['visited_since'])),
            )
            ->when(
                ! empty($filtros['not_visited_since']),
                fn ($q) => $q->whereDoesntHave('appointments', fn ($qq) => $qq
                    ->whereNotNull('checked_out_at')
                    ->where('checked_out_at', '>=', $filtros['not_visited_since'])),
            );
    }

    public function audienceCount(Broadcast $broadcast): int
    {
        return $this->audienceQuery($broadcast)->count();
    }

    /**
     * Genera los mensajes. Devuelve cuantos quedaron listos para salir.
     *
     * No manda nada por su cuenta: cada destinataria se convierte en una fila
     * de `messages`, y de ahi en adelante es el mismo camino que un
     * recordatorio -- cola, reintentos, costo por negocio, y bandeja de
     * salida si el negocio opera a mano.
     */
    public function dispatch(Broadcast $broadcast): int
    {
        if ($broadcast->status === Broadcast::STATUS_SENT) {
            return 0;
        }

        $broadcast->update(['status' => Broadcast::STATUS_SENDING]);

        $business = $broadcast->business;
        $enviados = 0;

        /*
         * Por lotes: un negocio con diez mil fichas no cabe en memoria, y
         * ademas asi el trabajo avanza aunque algo falle a la mitad -- los
         * que ya salieron quedaron escritos.
         */
        $this->audienceQuery($broadcast)->chunkById(200, function (Collection $clientes) use ($broadcast, $business, &$enviados) {
            foreach ($clientes as $cliente) {
                $mensaje = $this->dispatcher->queue(
                    $business,
                    Message::KIND_BROADCAST,
                    $cliente->phone,
                    $this->render($broadcast->body_template, $broadcast, $cliente),
                    null,
                    $cliente,
                    MessageTemplate::raw(
                        $broadcast->template_name,
                        $broadcast->template_language,
                        array_map(
                            fn (string $p) => $this->render($p, $broadcast, $cliente),
                            $broadcast->template_params ?? [],
                        ),
                    ),
                    $broadcast,
                );

                if ($mensaje !== null) {
                    $enviados++;
                }
            }
        });

        $broadcast->update([
            'status' => Broadcast::STATUS_SENT,
            'sent_at' => now(),
            'recipients' => $enviados,
        ]);

        Log::info('difusion enviada', ['broadcast_id' => $broadcast->id, 'destinatarias' => $enviados]);

        return $enviados;
    }

    /**
     * Las difusiones cuya hora llego.
     *
     * Ventana abierta hacia atras -- todo lo programado hasta ahora que siga
     * pendiente -- para que una corrida perdida se recupere sola en la
     * siguiente, igual que los recordatorios.
     *
     * @return Collection<int, Broadcast>
     */
    public function due(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        return Broadcast::withoutGlobalScope('business')
            ->where('status', Broadcast::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->with('business')
            ->get();
    }

    /** Los marcadores que puede usar quien escribe la difusion. */
    private function render(string $texto, Broadcast $broadcast, Client $cliente): string
    {
        return strtr($texto, [
            '{nombre}' => $cliente->name ?: 'Hola',
            '{negocio}' => $broadcast->business->name,
        ]);
    }
}
