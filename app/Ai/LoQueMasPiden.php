<?php

namespace App\Ai;

use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * El orden en que se ofrecen los servicios: el que más pide la gente,
 * primero.
 *
 * Hace falta porque una lista de WhatsApp aguanta diez filas y la
 * categoría Manicure tiene veintitrés. Alguien tiene que decidir cuáles
 * diez, y hasta ahora decidía el alfabeto: la clienta que pedía "las
 * manitos" veía Cambio de esmalte, Capping y cuatro Recubrimientos, y NO
 * veía Tradicional ni Semipermanente -- los dos que suman más de mil de
 * las citas del local. Las dos que de verdad quería quedaban fuera de la
 * pantalla.
 *
 * El dato no hay que inventarlo ni configurarlo: está en la agenda. Seis
 * años de citas dicen qué pide la gente mejor que cualquier orden que
 * alguien se siente a teclear -- y el local nunca lo tecleó, todos los
 * `sort_order` están en cero.
 *
 * Los servicios que el negocio SÍ numeró van primero, en su orden: ahí
 * dijo a propósito qué quiere empujar, y eso puede no ser lo más vendido
 * (lo nuevo, lo que deja más margen). Detrás de esos, los demás por
 * demanda -- numerar tres no es haber ordenado los cuarenta.
 */
final class LoQueMasPiden
{
    /*
     * Un año y no todo el histórico: un servicio que se dejó de prestar
     * hace tres años no puede seguir encabezando la lista por lo que
     * vendió entonces.
     */
    private const MESES = 12;

    // Seis horas. Esto cambia al ritmo de los hábitos de un barrio, no
    // al de una conversación; recalcularlo en cada mensaje es pagar una
    // consulta de agregación por algo que no se movió.
    private const TTL_SEGUNDOS = 21600;

    /**
     * Los mismos servicios, ordenados por lo que de verdad se pide.
     *
     * @param  Collection<int, Service>  $servicios
     * @return Collection<int, Service>
     */
    public static function ordenar(int $businessId, Collection $servicios): Collection
    {
        $cuantas = self::cuantasVeces($businessId);

        /*
         * Lo que el local ordenó a mano va primero, en su orden; el resto,
         * por lo que se pide.
         *
         * Antes bastaba UN servicio con `sort_order` para que el catálogo
         * entero volviera al alfabeto: con treinta y ocho en cero y dos
         * con número, "el negocio ordenó su catálogo" era falso, y
         * Tradicional -- el servicio más pedido del local -- quedaba en la
         * cuarta página de la lista, a cuatro toques de distancia.
         */
        return $servicios
            ->sortBy(
                fn (Service $s) => [
                    (int) $s->sort_order === 0 ? 1 : 0,
                    (int) $s->sort_order === 0 ? 0 : (int) $s->sort_order,
                    -($cuantas[$s->id] ?? 0),
                ],
                SORT_REGULAR,
            )
            ->values();
    }

    /**
     * Cuántas veces se pidió cada servicio en el último año.
     *
     * @return array<int, int>
     */
    private static function cuantasVeces(int $businessId): array
    {
        return Cache::remember(
            "ia:lo-que-mas-piden:{$businessId}",
            self::TTL_SEGUNDOS,
            fn () => DB::table('appointment_items')
                ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
                ->where('appointment_items.business_id', $businessId)
                // Una cita cancelada también dice qué pide la gente: la
                // pidió. Lo que no cuenta es lo que nunca se pidió.
                ->where('appointments.starts_at', '>=', now()->subMonths(self::MESES))
                ->whereNull('appointments.deleted_at')
                ->whereNotNull('appointment_items.service_id')
                ->groupBy('appointment_items.service_id')
                ->selectRaw('appointment_items.service_id, count(*) as veces')
                ->pluck('veces', 'appointment_items.service_id')
                ->map(fn ($v) => (int) $v)
                ->all(),
        );
    }

    /** Para cuando cambie la agenda de verdad y no se quiera esperar seis horas. */
    public static function olvidar(int $businessId): void
    {
        Cache::forget("ia:lo-que-mas-piden:{$businessId}");
    }
}
