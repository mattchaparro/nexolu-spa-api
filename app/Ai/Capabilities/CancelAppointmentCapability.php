<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\EnvioDirecto;
use App\Ai\HoraLegible;
use App\Models\Appointment;
use App\Models\ClientPenalty;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\ClientPortalService;
use App\Services\Scheduling\BookingService;
use App\Support\ChannelPhone;

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
            // Ya se le dijo la multa por cancelar tarde y dijo que sí.
            'acepta_multa' => ['nullable', 'boolean'],
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
            return $this->noLaEncuentro($caller, $arguments);
        }

        $tardia = $caller->isCustomer() && $this->portal->isLateCancellation($cita, $caller->business);

        /*
         * Cancelar tarde SE PUEDE, con multa: igual no iba a llegar, y así
         * al menos se libera la silla. Pero la multa se dice ANTES -- nadie
         * se entera de una multa después de haber dicho que sí.
         */
        if ($tardia && ! ($arguments['acepta_multa'] ?? false)) {
            return [
                'cancelada' => false,
                'requiere_multa' => true,
                'multa' => $this->portal->lateCancellationPenalty($caller->business),
                'motivo' => $this->avisoDeMulta($caller),
            ];
        }

        if (! $tardia && $caller->isCustomer() && ! $this->portal->canBeChanged($cita, $caller->business)) {
            return [
                'cancelada' => false,
                'motivo' => $this->portal->reasonToRefuse($cita, $caller->business)
                    ?? 'Esa cita ya no se puede cancelar. Dile que escriba al negocio.',
            ];
        }

        $this->booking->cancel($cita, $caller->user?->id, $arguments['motivo'] ?? null);

        if ($tardia) {
            $this->registrarMulta($caller, $cita);
        }

        $confirmada = $this->confirmarALaClienta($caller, $cita, $tardia);

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
    private function noLaEncuentro(AiCaller $caller, array $arguments): array
    {
        if ($caller->client === null) {
            return ['cancelada' => false, 'motivo' => 'No encuentro esa cita a tu nombre.'];
        }

        $proximas = $this->portal->upcoming($caller->client, $caller->business);

        if ($proximas->count() === 1) {
            // Con UNA cita, es esa: la misma regla (y la misma multa) que si
            // el id hubiera venido bien.
            return [
                ...$this->execute($caller, [...$arguments, 'cita_id' => $proximas->first()->id]),
                'nota' => 'El id que mandaste no era de sus citas; usé la única que tiene.',
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

    /** "Faltan menos de 3 horas: si cancelas ahora aplica la multa de $10.000." */
    private function avisoDeMulta(AiCaller $caller): string
    {
        $horas = max(1, (int) ceil((int) $caller->business->schedulingSetting('min_cancellation_notice_min') / 60));
        $multa = $this->portal->lateCancellationPenalty($caller->business);

        return $multa > 0
            ? sprintf('Faltan menos de %d hora(s) para tu cita: si cancelas ahora aplica la multa por cancelación tardía de *%s*.', $horas, $this->pesos($multa))
            : sprintf('Faltan menos de %d hora(s) para tu cita: la cancelación queda registrada como tardía.', $horas);
    }

    /**
     * La multa queda en la ficha, y el equipo se entera en la bandeja.
     *
     * Con monto 0 también se anota: cancelar tarde una y otra vez es un
     * patrón que el local quiere ver aunque hoy no cobre por él.
     */
    private function registrarMulta(AiCaller $caller, Appointment $cita): void
    {
        $multa = $this->portal->lateCancellationPenalty($caller->business);
        $minutos = (int) now()->diffInMinutes($cita->starts_at, false);

        ClientPenalty::create([
            'business_id' => $caller->business->id,
            'client_id' => $cita->client_id,
            'appointment_id' => $cita->id,
            'kind' => ClientPenalty::KIND_LATE_CANCELLATION,
            'amount' => $multa,
            'reason' => 'Canceló por WhatsApp faltando '.max(0, $minutos).' minutos.',
        ]);

        $conversacion = WhatsappConversation::withoutGlobalScope('business')
            ->where('business_id', $caller->business->id)
            ->where('phone', ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO'))
            ->first();

        if ($conversacion === null) {
            return;
        }

        Message::create([
            'business_id' => $conversacion->business_id,
            'conversation_id' => $conversacion->id,
            'client_id' => $conversacion->client_id,
            'kind' => Message::KIND_STAFF,
            'direction' => Message::DIRECTION_OUT,
            'to' => $conversacion->phone,
            'body' => sprintf(
                '⚑ Cancelación TARDÍA de la cita #%d (faltaban %d min). Multa de %s registrada en su ficha; el cupo quedó libre.',
                $cita->id,
                max(0, $minutos),
                $this->pesos($multa),
            ),
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $conversacion->update(['last_message_at' => now(), 'read_at' => null]);
    }

    private function pesos(float $valor): string
    {
        return '$'.number_format($valor, 0, ',', '.');
    }

    /** "Tu cita quedó cancelada", directo por el canal: es demasiado
     * importante para depender de que el modelo lo redacte. */
    private function confirmarALaClienta(AiCaller $caller, Appointment $cita, bool $tardia = false): bool
    {
        if (! $caller->isCustomer() || $caller->channel !== 'whatsapp') {
            return false;
        }

        $tz = $caller->business->businessTimezone();
        $servicios = $cita->items->map(fn ($i) => $i->service?->name)->filter()->unique()->implode(' y ');
        $multa = $this->portal->lateCancellationPenalty($caller->business);

        return app(EnvioDirecto::class)->texto($caller, sprintf(
            'Listo, tu cita de *%s* del *%s* quedó cancelada ✅%s',
            $servicios !== '' ? $servicios : 'la cita',
            $cita->starts_at->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm a'),
            $tardia && $multa > 0 ? "\nQuedó registrada la multa por cancelación tardía de *".$this->pesos($multa).'*.' : '',
        ));
    }
}
