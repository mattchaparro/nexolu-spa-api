<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\ServiceRating;
use App\Support\Ratings\Comentario;
use App\Support\Ratings\Nota;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que ve una profesional de si misma.
 *
 * Se organiza alrededor de "cuanto voy a cobrar", no de los numeros del
 * negocio. Es la idea que Blue Souls tenia bien: a una manicurista le importa
 * su comision acumulada y cuanto lleva del periodo, no la facturacion total
 * del spa.
 */
class MyWorkController
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $resource = $user->resource;

        if ($resource === null) {
            return response()->json([
                'resource' => null,
                'message' => 'Tu usuario no está asociado a nadie de la agenda.',
            ]);
        }

        $tz = $user->business->businessTimezone();
        $now = CarbonImmutable::now($tz);

        return response()->json([
            'resource' => ['id' => $resource->id, 'name' => $resource->name],
            'today' => $this->earnedBetween($resource->id, $now->startOfDay(), $now->addDay()->startOfDay()),
            'week' => $this->earnedBetween($resource->id, $now->startOfWeek(), $now->addDay()->startOfDay()),
            'month' => $this->earnedBetween($resource->id, $now->startOfMonth(), $now->addDay()->startOfDay()),
            // Lo que atendio pero todavia no cobro: lo primero que tiene que
            // resolver antes de irse.
            'pending_checkout' => $this->pendingCheckout($resource->id, $now),
            'agenda' => $this->agenda($resource->id, $now, $tz),
            'ratings' => $this->calificaciones($resource->id, $now, $tz),
        ]);
    }

    /**
     * Lo que opinaron de ELLA, y nada mas.
     *
     * Solo sus notas: ni las del negocio ni las de las compañeras. No es
     * timidez con el dato -- es que un tablero comparativo entre dos
     * manicuristas que trabajan a un metro deja de ser una herramienta y pasa
     * a ser un problema entre ellas. Cada una ve como va, y quien compara es
     * quien paga.
     *
     * SIN NOMBRE NI TELEFONO DE QUIEN OPINO. El comentario solo, que es lo
     * util: la base de clientas es del negocio.
     *
     * @return array<string, mixed>
     */
    private function calificaciones(int $resourceId, CarbonImmutable $now, string $tz): array
    {
        $desde = $now->startOfMonth()->subMonths(5);

        $notas = ServiceRating::where('resource_id', $resourceId)
            ->where('created_at', '>=', $desde->utc())
            ->orderByDesc('created_at')
            ->get();

        /*
         * El mes anterior COMPLETO, no "los ultimos 30 dias".
         *
         * Un dia 3 del mes lleva tres notas: decir "bajaste" con eso es ruido,
         * y el aviso que mas rapido deja de creerse es el que se dispara solo.
         */
        $mesActual = $notas->filter(fn (ServiceRating $r) => $r->created_at?->setTimezone($tz)->isSameMonth($now));
        $mesAnterior = $notas->filter(
            fn (ServiceRating $r) => $r->created_at?->setTimezone($tz)->isSameMonth($now->subMonth()),
        );

        $atencion = fn ($filas) => Nota::promedio(
            $filas->map(fn (ServiceRating $r) => [$r->staff_rating, $r->staff_scale]),
        );

        return [
            'count' => $notas->count(),
            'since' => $desde->toDateString(),

            // Las tres cosas que pregunta la encuesta, cada una sobre SU
            // escala. Ver App\Support\Ratings\Nota.
            'attention' => $atencion($notas),
            'service' => Nota::promedio(
                $notas->map(fn (ServiceRating $r) => [$r->service_rating, $r->service_scale]),
            ),
            'punctuality' => Nota::promedio(
                $notas->map(fn (ServiceRating $r) => [$r->punctuality_rating, $r->punctuality_scale]),
            ),

            'this_month' => ['count' => $mesActual->count(), 'attention' => $atencion($mesActual)],
            'previous_month' => ['count' => $mesAnterior->count(), 'attention' => $atencion($mesAnterior)],

            /*
             * Los comentarios escritos, los mas nuevos primero.
             *
             * Son lo que de verdad mueve a alguien: un promedio de 96% no dice
             * que hacer distinto, y "me encanto como me quedaron las uñas" si
             * dice que se esta haciendo bien.
             */
            'comments' => $notas
                // Solo lo que es una opinion. La ultima pregunta de la
                // encuesta es abierta y la mayoria contesta "no, gracias":
                // una lista de "No" no motiva a nadie, parece que le
                // estuvieran diciendo que no a algo.
                ->filter(fn (ServiceRating $r) => Comentario::esOpinion($r->comment))
                ->take(15)
                ->map(fn (ServiceRating $r) => [
                'comment' => $r->comment,
                'attention' => Nota::porcentaje($r->staff_rating, $r->staff_scale),
                'date' => $r->created_at?->setTimezone($tz)->toDateString(),
            ])->values()->all(),
        ];
    }

    /**
     * Lo cobrado y la comision en un rango, por fecha de COBRO.
     *
     * @return array{services: int, charged: float, commission: float}
     */
    private function earnedBetween(int $resourceId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = AppointmentItem::where('resource_id', $resourceId)
            ->whereHas('appointment', fn ($q) => $q
                ->whereNotNull('checked_out_at')
                ->whereBetween('checked_out_at', [$from->utc(), $to->utc()]))
            ->get();

        return [
            'services' => $items->count(),
            'charged' => round((float) $items->sum('final_price'), 2),
            'commission' => round((float) $items->sum('commission_amount'), 2),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pendingCheckout(int $resourceId, CarbonImmutable $now): array
    {
        return Appointment::query()
            /*
             * `completed` TAMBIEN cuenta, y es el caso que mas duele.
             *
             * Marcar la cita como completada no cobra: son dos actos
             * distintos a proposito. Pero mientras esta lista no la incluyo,
             * marcarla "Completada" la sacaba de este aviso -- el servicio
             * quedaba atendido, sin cobrar, invisible, fuera de la venta del
             * dia y fuera de su comision. La unica forma de enterarse era que
             * a fin de mes le faltara plata.
             *
             * Lo que define "pendiente de cobro" es `checked_out_at`, no el
             * estado. Cancelada y no-asistio quedan fuera porque ahi no hay
             * nada que cobrar.
             */
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_IN_PROGRESS,
                Appointment::STATUS_COMPLETED,
            ])
            ->whereNull('checked_out_at')
            // Ya paso su hora: una cita de mas tarde no esta "pendiente de
            // cobro", simplemente todavia no ocurrio.
            ->where('starts_at', '<=', $now->utc())
            ->whereHas('items', fn ($q) => $q->where('resource_id', $resourceId))
            ->with('items.service')
            ->orderBy('starts_at')
            ->limit(20)
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'client_name' => $a->client_name,
                'service_name' => $a->items->first()?->service?->name,
                'label' => $a->starts_at?->setTimezone($a->business->businessTimezone())->format('d/m H:i'),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function agenda(int $resourceId, CarbonImmutable $now, string $tz): array
    {
        $start = $now->startOfDay();

        return Appointment::query()
            ->whereIn('status', Appointment::activeStatuses())
            ->whereBetween('starts_at', [$start->utc(), $start->addDay()->utc()])
            ->whereHas('items', fn ($q) => $q->where('resource_id', $resourceId))
            ->with(['items.service', 'client'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'time' => $a->starts_at?->setTimezone($tz)->format('H:i'),
                'client_name' => $a->client_name,
                'client_id' => $a->client_id,
                'service_name' => $a->items->first()?->service?->name,
                'status' => $a->status,
                'is_paid' => $a->checked_out_at !== null,
                'total' => $a->total === null ? null : (float) $a->total,
            ])
            ->values()
            ->all();
    }
}
