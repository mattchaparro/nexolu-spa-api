<?php

namespace Tests\Feature\Scheduling;

use App\Models\Business;
use App\Models\Resource;
use App\Models\ResourceSchedule;
use App\Models\Service;
use App\Support\BusinessFeaturePresets;
use Carbon\CarbonImmutable;

/**
 * Constructor de escenarios de agenda para las pruebas.
 *
 * El negocio vive en America/Bogota a proposito, no en UTC: la mayoria de los
 * errores de zona horaria son invisibles cuando ambas coinciden.
 */
trait SchedulingScenario
{
    protected function makeBusiness(array $settings = [], string $timezone = 'America/Bogota'): Business
    {
        return Business::create([
            'name' => 'Spa de prueba',
            'slug' => 'spa-prueba-'.uniqid(),
            'vertical' => BusinessFeaturePresets::VERTICAL_SPA_UNAS,
            'timezone' => $timezone,
            'country_code' => 'CO',
            'currency' => 'COP',
            'subscription_plan' => BusinessFeaturePresets::PLAN_FULL,
            'feature_flags' => BusinessFeaturePresets::full(),
            'scheduling_settings' => array_merge([
                'slot_granularity_min' => 15,
                'min_booking_notice_min' => 0,
                'min_cancellation_notice_min' => 180,
                'max_booking_horizon_days' => 60,
            ], $settings),
            'is_active' => true,
        ]);
    }

    /**
     * Un recurso con horario en los dias indicados (ISO-8601: 1 = lunes).
     *
     * @param  list<int>  $weekdays
     */
    protected function makeResource(
        Business $business,
        string $name = 'Maria',
        string $start = '09:00:00',
        string $end = '18:00:00',
        array $weekdays = [1, 2, 3, 4, 5, 6],
        ?int $locationId = null,
    ): Resource {
        $resource = Resource::create([
            'business_id' => $business->id,
            // Igual que en el alta real: sin sede explicita cae en la
            // principal, nunca en nulo.
            'location_id' => $locationId ?? $business->primaryLocation()?->id,
            'type' => Resource::TYPE_STAFF,
            'name' => $name,
            'is_bookable_online' => true,
            'is_active' => true,
        ]);

        foreach ($weekdays as $weekday) {
            ResourceSchedule::create([
                'business_id' => $business->id,
                'resource_id' => $resource->id,
                'weekday' => $weekday,
                'start_time' => $start,
                'end_time' => $end,
                'effective_from' => '2020-01-01',
            ]);
        }

        return $resource;
    }

    /**
     * @param  list<Resource>  $resources
     */
    protected function makeService(
        Business $business,
        int $durationMin = 60,
        array $resources = [],
        int $bufferBefore = 0,
        int $bufferAfter = 0,
        string $name = 'Manicure',
    ): Service {
        $service = Service::create([
            'business_id' => $business->id,
            'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'duration_min' => $durationMin,
            'buffer_before_min' => $bufferBefore,
            'buffer_after_min' => $bufferAfter,
            'price' => 50000,
            'commission_rate' => 0.30,
            'is_bookable_online' => true,
            'is_active' => true,
        ]);

        if ($resources !== []) {
            $service->resources()->attach(collect($resources)->pluck('id'));
        }

        return $service;
    }

    /** Un miercoles cualquiera, lejos de cualquier borde de mes o de año. */
    protected function wednesday(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-16', 'America/Bogota')->startOfDay();
    }

    /** Las horas de inicio devueltas, en formato HH:MM y hora local. */
    protected function startTimes(array $slots): array
    {
        return array_map(
            fn (array $slot) => $slot['starts_at']->setTimezone('America/Bogota')->format('H:i'),
            $slots,
        );
    }
}
