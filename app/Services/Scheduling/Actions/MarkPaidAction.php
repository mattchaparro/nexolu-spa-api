<?php

namespace App\Services\Scheduling\Actions;

use App\Models\PaymentMethod;
use App\Services\Scheduling\CheckoutService;
use App\Support\Scheduling\StageActionCatalog;

/**
 * Deja la cita cobrada al entrar a la etapa.
 *
 * CRITICA: mueve plata. Si falla, la transicion entera se deshace. Una cita que
 * quedo marcada como "Lista y cobrada" pero sin cobro registrado descuadra el
 * cierre del dia, y el descuadre aparece horas despues sin nada que lo explique.
 */
class MarkPaidAction implements StageAction
{
    public function __construct(private readonly CheckoutService $checkout) {}

    public function type(): string
    {
        return StageActionCatalog::MARK_PAID;
    }

    public function execute(StageActionContext $context): StageActionResult
    {
        $appointment = $context->appointment;

        if ($appointment->checked_out_at !== null) {
            // Ya estaba cobrada. No es un fallo -- alguien cobro en caja y
            // despues movio la etiqueta.
            return StageActionResult::skipped('La cita ya estaba cobrada.');
        }

        if ($context->actor === null) {
            // El cobro se registra a nombre de quien lo hace, y eso alimenta
            // el turno de caja. Sin persona no hay a quien atribuirselo.
            return StageActionResult::failed('Un cobro necesita quedar a nombre de alguien.');
        }

        $method = $this->resolveMethod($context);

        if (! $method) {
            return StageActionResult::failed('La etapa no tiene un medio de pago configurado.');
        }

        $this->checkout->checkout($appointment, $method, $context->actor);

        return StageActionResult::ok("Cobrado con {$method->name}.");
    }

    private function resolveMethod(StageActionContext $context): ?PaymentMethod
    {
        $configured = $context->config('payment_method_id');

        $query = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $context->appointment->business_id)
            ->where('is_active', true);

        if ($configured) {
            return (clone $query)->whereKey($configured)->first();
        }

        // Sin medio configurado se usa el de caja: si el negocio automatizo el
        // cobro es porque cobra en el mostrador.
        return (clone $query)->where('counts_as_cash', true)->orderBy('sort_order')->first()
            ?? $query->orderBy('sort_order')->first();
    }
}
