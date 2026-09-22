<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\EnvioDirecto;
use App\Ai\HoraLegible;
use App\Models\Appointment;
use App\Services\ClientPortalService;
use App\Services\Scheduling\BookingService;

/**
 * Cancelar. Solo la cita de quien escribe, y solo si aun esta a tiempo.
 *
 * El id de la cita lo propone el modelo, asi que se trata como lo que es:
 * un numero que llego por la red. Se comprueba que la cita sea del negocio Y
 * de esa clienta antes de tocarla -- sin eso, probar ids consecutivos
 * cancelaria la agenda del local entera desde un chat.
 */
class CancelAppointmentCapability implements Capability
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly ClientPortalService $portal,
    ) {}

    public function requiredPermission(): ?string
    {
        return 'citas.cancelar';
    }

    public function requiredFeature(): ?string
    {
        return 'scheduling';
    }

    public function allowsCustomers(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cita_id' => ['required', 'integer'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $cita = Appointment::withoutGlobalScope('business')
            ->where('business_id', $caller->business->id)
            ->find($arguments['cita_id']);

        // Una cita ajena y una inexistente se responden igual: que exista no
        // es asunto de quien pregunta.
        if ($cita === null || ($caller->isCustomer() && $cita->client_id !== $caller->client?->id)) {
            return $this->noLaEncuentro($caller);
        }

        if ($caller->isCustomer() && ! $this->portal->canBeChanged($cita, $caller->business)) {
            return [
                'cancelada' => false,
                'motivo' => $this->portal->reasonToRefuse($cita, $caller->business)
                    ?? 'Esa cita ya no se puede cancelar. Dile que escriba al negocio.',
            ];
        }

        $this->booking->cancel($cita, $caller->user?->id, $arguments['motivo'] ?? null);

        $confirmada = $this->confirmarALaClienta($caller, $cita);

        return [
            'cancelada' => true,
            'id' => $cita->id,
            'confirmacion_enviada' => $confirmada,
            'instruccion' => $confirmada
                ? 'La confirmación de la cancelación YA le llegó. Responde con una cadena vacía.'
                : 'Dile en una frase que la cita quedó cancelada.',
        ];
    }

    /**
     * El id que mandó no es de una cita suya.
     *
     * Pasó en la primera prueba real: el modelo llamó con `cita_id: 1` --
     * el id de la FICHA de la clienta, no el de la cita (6709) -- y ante el
     * "no la encuentro" concluyó que la cita no existía y escaló a un
     * humano. La clienta tenía UNA sola cita y estaba ahí.
     *
     * Dos cosas: si solo tiene una próxima, se cancela esa (es lo que
     * cualquier persona entendería por "cancélame la cita"); y si tiene
     * varias, el error le DICE al modelo cuáles son y con qué id, en vez de
     * dejarlo adivinando.
     */
    private function noLaEncuentro(AiCaller $caller): array
    {
        if ($caller->client === null) {
            return ['cancelada' => false, 'motivo' => 'No encuentro esa cita a tu nombre.'];
        }

        $proximas = $this->portal->upcoming($caller->client, $caller->business);

        if ($proximas->count() === 1) {
            $unica = $proximas->first();

            if (! $this->portal->canBeChanged($unica, $caller->business)) {
                return [
                    'cancelada' => false,
                    'motivo' => $this->portal->reasonToRefuse($unica, $caller->business)
                        ?? 'Esa cita ya no se puede cancelar. Dile que escriba al negocio.',
                ];
            }

            $this->booking->cancel($unica, $caller->user?->id, null);

            $confirmada = $this->confirmarALaClienta($caller, $unica);

            return [
                'cancelada' => true,
                'id' => $unica->id,
                'confirmacion_enviada' => $confirmada,
                'instruccion' => $confirmada
                    ? 'La confirmación de la cancelación YA le llegó. Responde con una cadena vacía.'
                    : 'Dile en una frase que la cita quedó cancelada.',
            ];
        }

        if ($proximas->isEmpty()) {
            return ['cancelada' => false, 'motivo' => 'No tienes citas próximas para cancelar.'];
        }

        $tz = $caller->business->businessTimezone();

        return [
            'cancelada' => false,
            'motivo' => 'Ese id no es de una cita tuya. Estas son las que tienes; vuelve a '
                .'llamarme con el `id` correcto.',
            'citas' => $proximas->map(fn (Appointment $a) => [
                'id' => $a->id,
                'fecha' => $a->starts_at?->setTimezone($tz)->format('Y-m-d'),
                'hora' => HoraLegible::de($a->starts_at, $tz),
            ])->all(),
        ];
    }

    /** "Tu cita quedó cancelada", directo por el canal: es demasiado
     * importante para depender de que el modelo lo redacte. */
    private function confirmarALaClienta(AiCaller $caller, Appointment $cita): bool
    {
        if (! $caller->isCustomer() || $caller->channel !== 'whatsapp') {
            return false;
        }

        $tz = $caller->business->businessTimezone();
        $servicios = $cita->items->map(fn ($i) => $i->service?->name)->filter()->unique()->implode(' y ');

        return app(EnvioDirecto::class)->texto($caller, sprintf(
            'Listo, tu cita de *%s* del *%s* quedó cancelada ✅',
            $servicios !== '' ? $servicios : 'la cita',
            $cita->starts_at->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a'),
        ));
    }
}
