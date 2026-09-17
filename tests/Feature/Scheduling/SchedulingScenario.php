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
    private const SCENARIO_WEDNESDAY = '2026-09-16';

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

    /**
     * Un miercoles cualquiera, lejos de cualquier borde de mes o de año.
     *
     * Es una fecha fija y la agenda nunca ofrece horas pasadas, asi que solo
     * sirve con el reloj anclado antes (ver freezeClockBeforeWednesday). El
     * chequeo existe porque sin el la falla no dice nada: la disponibilidad
     * vuelve vacia y la prueba se cae comparando contra [].
     */
    protected function wednesday(): CarbonImmutable
    {
        $miercoles = CarbonImmutable::parse(self::SCENARIO_WEDNESDAY, 'America/Bogota')->startOfDay();

        if (CarbonImmutable::now()->greaterThan($miercoles->endOfDay())) {
            $this->fail(
                'El reloj ya paso el miercoles del escenario. '
                .'Llama $this->freezeClockBeforeWednesday() en el setUp de '.static::class.'.',
            );
        }

        return $miercoles;
    }

    /**
     * Ancla el reloj el lunes antes de wednesday(), a las 08:00 hora Bogota.
     *
     * El 17 de septiembre de 2026 ese miercoles quedo en el pasado y medio
     * centenar de pruebas se cayo de golpe sin que cambiara una linea de
     * codigo. Se congela el reloj en vez de correr la fecha porque correrla
     * solo aplaza la misma bomba, y porque hay pruebas que afirman sobre la
     * fecha literal. Lunes y no el mismo miercoles: deja margen de sobra para
     * la anticipacion minima de reserva y de cancelacion, y "hoy" sigue
     * siendo un dia en que el recurso trabaja.
     */
    protected function freezeClockBeforeWednesday(): void
    {
        $this->travelTo(
            CarbonImmutable::parse(self::SCENARIO_WEDNESDAY, 'America/Bogota')->subDays(2)->setTime(8, 0),
        );
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
