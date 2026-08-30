<?php

namespace App\Services\Discounts;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\DiscountCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Que campana aplica a una cita, y por cuanto.
 *
 * DOS REGLAS QUE NO SON OBVIAS:
 *
 * 1. La vigencia se mide contra la fecha de la CITA, no la del cobro. Quien
 *    reserva durante el mes de la madre reservo por ese precio; que el local
 *    cobre al dia siguiente no puede subirle la cuenta.
 *
 * 2. Si dos campanas cubren la misma cita, gana UNA -- la que mas descuente --
 *    y no se suman. Dos promociones encimadas dan un descuento que nadie
 *    decidio, y en un negocio de margen chico eso se nota rapido.
 */
class CampaignService
{
    /** Las campanas vigentes ese dia. */
    public function activeOn(Business $business, string $date): Collection
    {
        if (! $business->hasFeature('promotions')) {
            return collect();
        }

        return DiscountCampaign::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->with(['services', 'categories'])
            ->get();
    }

    /**
     * La mejor campana para esta cita, con cuanto descuenta.
     *
     * @return array{campaign: DiscountCampaign, amount: float}|null
     */
    public function bestFor(Appointment $appointment): ?array
    {
        $business = $appointment->business;
        $tz = $business->businessTimezone();

        // La fecha de la CITA, en la zona del negocio: una cita de las 8pm no
        // puede caer en el dia siguiente por leerla en UTC.
        $date = CarbonImmutable::parse($appointment->starts_at)->setTimezone($tz)->toDateString();

        $campaigns = $this->activeOn($business, $date);

        if ($campaigns->isEmpty()) {
            return null;
        }

        $items = $appointment->items()->with('service')->get();
        $mejor = null;

        foreach ($campaigns as $campaign) {
            $amount = 0.0;

            foreach ($items as $item) {
                /*
                 * Una garantia no entra en ninguna campana: ya vale cero, y
                 * descontarle algo mas solo agregaria ruido a la cuenta.
                 */
                if ($item->is_warranty || $item->service === null) {
                    continue;
                }

                if ($campaign->covers($item->service)) {
                    $amount += $campaign->discountForPrice((float) $item->price);
                }
            }

            $amount = round($amount, 2);

            if ($amount > 0 && ($mejor === null || $amount > $mejor['amount'])) {
                $mejor = ['campaign' => $campaign, 'amount' => $amount];
            }
        }

        return $mejor;
    }

    /**
     * Cuanto descontaria una campana sobre una lista de servicios sueltos.
     *
     * Para la pagina publica y la pantalla de agendar, que necesitan mostrar
     * el precio con promocion ANTES de que exista la cita.
     *
     * @param  Collection<int, \App\Models\Service>  $services
     * @return array{campaign: DiscountCampaign, amount: float}|null
     */
    public function bestForServices(Business $business, Collection $services, string $date): ?array
    {
        $mejor = null;

        foreach ($this->activeOn($business, $date) as $campaign) {
            $amount = 0.0;

            foreach ($services as $service) {
                if ($campaign->covers($service)) {
                    $amount += $campaign->discountForPrice((float) $service->price);
                }
            }

            $amount = round($amount, 2);

            if ($amount > 0 && ($mejor === null || $amount > $mejor['amount'])) {
                $mejor = ['campaign' => $campaign, 'amount' => $amount];
            }
        }

        return $mejor;
    }
}
