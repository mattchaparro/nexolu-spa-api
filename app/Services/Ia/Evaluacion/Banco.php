<?php

namespace App\Services\Ia\Evaluacion;

use App\Ai\EsUnaPrueba;
use App\Ai\OpcionesEnviadas;
use App\Ai\ServiciosPendientes;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\WhatsappConversation;
use Illuminate\Support\Collection;

/**
 * El banco de pruebas: una conversación de mentira sobre datos de verdad,
 * y la garantía de dejarlo todo como estaba.
 *
 * Lo comparten la evaluación por turnos (`ia:evaluar`) y el simulador de
 * clientas (`ia:simular`). Antes vivía dentro del comando de evaluación y
 * cada trampa se aprendió a golpes: la transacción que no protegía nada y
 * trancaba la conversación real; el `save()` que no restauraba la pausa y
 * dejó al dueño una hora sin bot; la marca de tiempo con microsegundos
 * que dejó setenta y cinco citas de prueba en la agenda del salón. Tener
 * dos copias de eso es tener dos sitios donde volver a caer.
 *
 * Las reglas, para quien lo use:
 *  - Conversa con un teléfono de VERDAD (`IA_EVAL_PHONE`): si algo se
 *    escapa, que le llegue a quien está probando. Los envíos se cortan en
 *    el canal con `EsUnaPrueba`.
 *  - Nada corre dentro de una transacción abierta: quien agenda es el
 *    Core, en otro request, y una transacción solo tranca la fila.
 *  - Todo lo que nace marcado se borra al terminar, y nada más.
 */
final class Banco
{
    public function __construct(private readonly Business $business) {}

    public static function telefono(?string $pedido = null): string
    {
        return ltrim((string) ($pedido ?: config('services.ia_eval.phone')), '+');
    }

    /**
     * Deja lista una conversación para hablar con el bot.
     *
     * Reusa la ficha y la conversación reales del teléfono -- hay índices
     * únicos, y dos fichas con el mismo número es justo el enredo que hace
     * que el bot no encuentre las citas de quien escribe -- pero arranca
     * un hilo NUEVO del Core: si no, las clientas inventadas quedan
     * pegadas a la memoria de la charla real.
     */
    public function preparar(string $telefono, bool $conCita = false, ?string $nombre = null): Sesion
    {
        /*
         * Un segundo antes, no `now()`: `created_at` se guarda al segundo
         * y una cita creada en ESTE mismo segundo quedaría con un
         * `created_at` anterior a la marca. Pasó, y la limpieza no la vio.
         */
        $desde = now()->subSecond();

        $cliente = Client::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->where('phone', $telefono)
            ->first()
            ?? Client::create([
                'business_id' => $this->business->id,
                'name' => 'Evaluación',
                'phone' => $telefono,
                'is_active' => true,
            ]);

        $conversacion = WhatsappConversation::withoutGlobalScope('business')
            ->firstOrNew([
                'business_id' => $this->business->id,
                'phone' => $telefono,
            ]);

        $comoEstaba = $conversacion->exists
            ? $conversacion->only([
                'client_id', 'ia_conversation_id', 'last_message_at', 'last_inbound_at',
                'status', 'read_at', 'agent_paused_until',
            ])
            : null;

        $conversacion->forceFill([
            'client_id' => $cliente->id,
            'ia_conversation_id' => null,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
            'agent_paused_until' => null,
        ])->save();

        // Las marcas de "ya le mandé opciones" viven en caché, fuera de
        // todo esto: sin borrarlas, la sesión hereda las de la anterior.
        OpcionesEnviadas::olvidar($telefono);
        ServiciosPendientes::olvidar($telefono);
        EsUnaPrueba::enviados($telefono);

        if ($conCita) {
            $this->citaDePrueba($cliente);
        }

        /*
         * La ficha es la del dueño, así que el bot saludaría a "Mateo" a
         * una abuela que se llama Gloria y la conversación se iría en
         * corregir el nombre. Se le presta el nombre de la persona
         * simulada y se devuelve al terminar.
         */
        $nombreOriginal = $cliente->name;

        if ($nombre !== null) {
            $cliente->forceFill(['name' => $nombre])->save();
        }

        return new Sesion($cliente, $conversacion, $comoEstaba, $desde, $nombreOriginal);
    }

    /**
     * Borrar lo que dejó la sesión, sin tocar lo que ya estaba.
     *
     * Las citas se borran por el SELLO y no solo por la fecha: la ficha
     * es de una persona de verdad y borrar "lo creado en los últimos
     * segundos" casi nunca se equivoca -- y "casi nunca" no alcanza con la
     * cita de alguien.
     */
    public function limpiar(Sesion $sesion): void
    {
        Appointment::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->where('client_id', $sesion->cliente->id)
            ->where('created_at', '>=', $sesion->desde)
            ->where('notes', 'like', '%'.EsUnaPrueba::SELLO.'%')
            ->get()
            ->each(function (Appointment $cita) {
                // `forceDelete`: un borrado suave deja la fila en la
                // papelera, y estas citas no son algo que el local
                // canceló, son algo que no debió existir.
                $cita->items()->forceDelete();
                $cita->forceDelete();
            });

        Message::withoutGlobalScopes()
            ->where('conversation_id', $sesion->conversacion->id)
            ->where('created_at', '>=', $sesion->desde)
            ->delete();

        OpcionesEnviadas::olvidar($sesion->cliente->phone);
        ServiciosPendientes::olvidar($sesion->cliente->phone);
        EsUnaPrueba::enviados($sesion->cliente->phone);

        if ($sesion->nombreOriginal !== null && $sesion->cliente->name !== $sesion->nombreOriginal) {
            Client::withoutGlobalScope('business')
                ->whereKey($sesion->cliente->getKey())
                ->update(['name' => $sesion->nombreOriginal]);
        }

        if ($sesion->comoEstaba === null) {
            $sesion->conversacion->delete();

            return;
        }

        /*
         * UPDATE directo, no `save()`: quien pausa al bot durante la
         * sesión es OTRO proceso, así que para el modelo en memoria volver
         * al valor anterior "no es un cambio" y `save()` no escribía nada.
         * Así quedó el dueño una hora sin bot.
         */
        WhatsappConversation::withoutGlobalScope('business')
            ->whereKey($sesion->conversacion->getKey())
            ->update($sesion->comoEstaba);
    }

    /**
     * Las citas que la sesión dejó agendadas, para juzgar el resultado.
     *
     * @return Collection<int, Appointment>
     */
    public function citasCreadas(Sesion $sesion): Collection
    {
        return Appointment::withoutGlobalScopes()
            ->with('items.service', 'items.resource')
            ->where('business_id', $this->business->id)
            ->where('client_id', $sesion->cliente->id)
            ->where('created_at', '>=', $sesion->desde)
            ->where('notes', 'like', '%'.EsUnaPrueba::SELLO.'%')
            ->orderBy('starts_at')
            ->get();
    }

    /** Una cita próxima, para los casos de cancelar, mover o consultar. */
    private function citaDePrueba(Client $cliente): void
    {
        // El primer servicio del catálogo puede no tener a nadie que lo
        // preste: hay que buscar uno que SÍ, o la cita no se crea y el
        // caso mide otra cosa.
        $servicio = $this->business->services()->where('is_active', true)->get()
            ->first(fn ($s) => $s->resources()->exists());
        $recurso = $servicio?->resources()->first();

        if ($servicio === null || $recurso === null) {
            return;
        }

        $cita = Appointment::create([
            'business_id' => $this->business->id,
            'location_id' => $this->business->primaryLocation()?->id,
            'client_id' => $cliente->id,
            'client_name' => $cliente->name,
            'client_phone' => $cliente->phone,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_WHATSAPP_AGENT,
            'notes' => EsUnaPrueba::SELLO,
        ]);

        $cita->items()->create([
            'business_id' => $this->business->id,
            'service_id' => $servicio->id,
            'resource_id' => $recurso->id,
            'starts_at' => $cita->starts_at,
            'ends_at' => $cita->ends_at,
            'service_starts_at' => $cita->starts_at,
            'service_ends_at' => $cita->ends_at,
            'price' => $servicio->price,
            'sort_order' => 0,
        ]);
    }
}
