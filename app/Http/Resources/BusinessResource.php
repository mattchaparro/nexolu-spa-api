<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Business
 */
class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'vertical' => $this->vertical,
            'timezone' => $this->businessTimezone(),
            'currency' => $this->currency,

            // Ya resueltas. El front lee esto y nunca reimplementa la mezcla
            // de flags explicitos con los defaults del plan: tener dos
            // implementaciones de la misma logica es como el POS termino
            // mostrandole modulos no contratados a negocios del plan Basico.
            'resolved_features' => $this->resolvedFeatureFlags(),

            'scheduling_settings' => [
                'slot_granularity_min' => (int) $this->schedulingSetting('slot_granularity_min'),
                'min_booking_notice_min' => (int) $this->schedulingSetting('min_booking_notice_min'),
                'min_cancellation_notice_min' => (int) $this->schedulingSetting('min_cancellation_notice_min'),
                'max_booking_horizon_days' => (int) $this->schedulingSetting('max_booking_horizon_days'),
            ],
        ];
    }
}
