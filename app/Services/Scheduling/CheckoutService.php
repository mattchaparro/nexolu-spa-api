<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Loyalty\LoyaltyService;
use App\Support\Money\DiscountAllocator;
use Illuminate\Support\Facades\DB;

/**
 * Cierra una cita atendida y la cobra.
 *
 * Es el momento en que una cita deja de ser agenda y pasa a ser dinero: se
 * congelan el total, el metodo de pago y la comision de cada profesional.
 *
 * Se congelan y no se recalculan a proposito. Los precios del catalogo y los
 * porcentajes de comision cambian; un reporte de hace seis meses tiene que
 * seguir mostrando lo que de verdad se cobro y lo que de verdad se pago,
 * no lo que esas mismas reglas darian hoy.
 */
class CheckoutService
{
    public function __construct(
        private readonly StageTransitionService $transitions,
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * @param  array<int, float>  $itemPrices  Precio final por id de item, para
     *                                         ajustar a mano lo que se cobro.
     * @param  bool  $transition  Falso solo cuando quien llama es la propia
     *                            maquina de estados (accion `mark_paid`).
     * @param  float|null  $commissionDiscount  Cuanto del descuento le baja la
     *        comision a quien atendio. Null = todo, que es como se comportaba
     *        el sistema antes de que el descuento pudiera venir de un premio
     *        de fidelizacion.
     */
    public function checkout(
        Appointment $appointment,
        PaymentMethod $paymentMethod,
        User $by,
        float $discountAmount = 0,
        ?string $discountReason = null,
        array $itemPrices = [],
        bool $transition = true,
        ?float $commissionDiscount = null,
    ): Appointment {
        if ($appointment->checked_out_at !== null) {
            throw new \DomainException('Esta cita ya fue cobrada.');
        }

        if ($appointment->status === Appointment::STATUS_CANCELLED) {
            throw new \DomainException('No se puede cobrar una cita cancelada.');
        }

        if ($discountAmount < 0) {
            throw new \DomainException('El descuento no puede ser negativo.');
        }

        return DB::transaction(function () use ($appointment, $paymentMethod, $by, $discountAmount, $discountReason, $itemPrices, $transition, $commissionDiscount) {
            $items = $appointment->items()->lockForUpdate()->get();

            $subtotal = 0.0;

            foreach ($items as $item) {
                $item->final_price = round((float) ($itemPrices[$item->id] ?? $item->price), 2);
                $subtotal += $item->final_price;
            }

            if ($discountAmount > $subtotal) {
                throw new \DomainException('El descuento no puede superar el total.');
            }

            // El reparto proporcional y el redondeo viven en DiscountAllocator,
            // sin base de datos: es la aritmetica mas facil de romper de todo
            // el modulo y hay que poder probarla con casos escritos a mano.
            $charged = DiscountAllocator::allocate(
                $items->map(fn (AppointmentItem $item) => (float) $item->final_price)->all(),
                $discountAmount,
            );

            /*
             * La comision puede calcularse sobre una base DISTINTA de lo
             * cobrado.
             *
             * Un premio de fidelizacion lo regala el negocio para que la
             * clienta vuelva; el trabajo de quien atendio fue el mismo, asi
             * que ese descuento no tiene por que bajarle la comision. Un
             * descuento a mano si, normalmente. Cual es cual lo decide el
             * negocio (ver `Business::commissionBases()`), y quien llama ya
             * trae sumado cuanto del descuento SI baja la comision.
             *
             * Se reparte dos veces con el MISMO repartidor: si se restara el
             * descuento a mano del total repartido, el redondeo de las dos
             * cuentas podria no coincidir y la nomina quedaria con centavos
             * que nadie sabe explicar.
             */
            $commissionBase = $commissionDiscount === null
                || round($commissionDiscount, 2) === round($discountAmount, 2)
                ? $charged
                : DiscountAllocator::allocate(
                    $items->map(fn (AppointmentItem $item) => (float) $item->final_price)->all(),
                    min(max(0, (float) $commissionDiscount), $subtotal),
                );

            $commissionAmounts = DiscountAllocator::commissions(
                $commissionBase,
                $items->map(fn (AppointmentItem $item) => $item->commission_rate === null
                    ? null
                    : (float) $item->commission_rate)->all(),
            );

            $commissionTotal = 0.0;

            foreach ($items as $i => $item) {
                $item->commission_amount = $commissionAmounts[$i];
                $commissionTotal += $commissionAmounts[$i];
                $item->save();
            }

            $appointment->update([
                'payment_method_id' => $paymentMethod->id,
                'checked_out_at' => now(),
                'checked_out_by_user_id' => $by->id,
                'subtotal' => round($subtotal, 2),
                'discount_amount' => round($discountAmount, 2),
                'discount_reason' => $discountReason,
                'total' => round($subtotal - $discountAmount, 2),
                'commission_total' => round($commissionTotal, 2),
            ]);

            /*
             * El estado lo mueve la maquina de estados, no este servicio.
             * Asi el cambio queda registrado, valida que el salto sea legal, y
             * dispara lo que el negocio haya configurado para "completada"
             * -- el mensaje de agradecimiento, por ejemplo.
             *
             * `$transition = false` cuando quien llama ES la maquina: la
             * accion `mark_paid` entra por aca con el estado ya movido, y
             * volver a moverlo desde adentro se llamaria a si mismo sin fin.
             */
            if ($transition) {
                $this->transitions->moveToStatus($appointment, Appointment::STATUS_COMPLETED, $by);
            }

            /*
             * El sello se cuenta al COBRAR, aca y no como accion opcional del
             * flujo de etapas. Un programa de fidelizacion que solo funciona
             * si el negocio se acordo de cablearlo en su workflow es un
             * programa que para la mayoria no funciona en silencio, y de eso
             * se entera la clienta en el mostrador.
             *
             * Va despues del update: `earnFor` lee el `total` ya congelado
             * para decidir si la visita llega al minimo.
             */
            $this->loyalty->earnFor($appointment->fresh());

            return $appointment->fresh(['items.service', 'items.resource', 'paymentMethod']);
        });
    }

    /**
     * Deshace un cobro.
     *
     * NO borra la cita ni libera el horario: el servicio se presto igual. Solo
     * revierte la parte de dinero, para corregir un metodo de pago o un
     * descuento mal digitado sin tener que inventar una cita nueva.
     */
    public function undo(Appointment $appointment, ?User $by = null): Appointment
    {
        if ($appointment->checked_out_at === null) {
            throw new \DomainException('Esta cita no ha sido cobrada.');
        }

        return DB::transaction(function () use ($appointment, $by) {
            $appointment->items()->update([
                'final_price' => null,
                'commission_amount' => null,
            ]);

            /*
             * El premio que se haya usado vuelve a estar disponible: deshacer
             * un cobro corrige la plata, no le quita a la clienta un premio
             * que ya se habia ganado.
             *
             * El SELLO no se borra: la visita ocurrio igual. Y si se vuelve a
             * cobrar, el unico por cita lo impide duplicar.
             */
            $this->loyalty->release($appointment);

            $appointment->update([
                'payment_method_id' => null,
                'checked_out_at' => null,
                'checked_out_by_user_id' => null,
                'subtotal' => null,
                'discount_amount' => 0,
                'discount_reason' => null,
                'total' => null,
                'commission_total' => null,
            ]);

            // Vuelve a "confirmada", no a "sin confirmar": deshacer un cobro
            // corrige la plata, no borra que el cliente vino.
            $this->transitions->moveToStatus($appointment, Appointment::STATUS_CONFIRMED, $by);

            return $appointment->fresh(['items.service', 'items.resource']);
        });
    }
}
