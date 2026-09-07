<?php

namespace App\Services\Migration\Importadores;

use App\Models\ServiceRating;
use Illuminate\Support\Facades\DB;

/**
 * Las calificaciones que dejaron las clientas.
 *
 * Van despues del historial porque cada una cuelga de la atencion que
 * califico. Y valen la pena: la pagina publica del negocio muestra estrellas
 * por profesional, y estrenarla en cero -- con 189 opiniones reales
 * guardadas en el sistema viejo -- seria empezar de nuevo una reputacion que
 * ya existe.
 *
 * El esquema viejo tiene TRES notas por opinion (el servicio, quien atendio y
 * la puntualidad) y el nuevo tambien, asi que el mapeo es directo. Lo unico
 * que cambia es de que cuelgan: alla del `employee_service`, aca de la cita
 * migrada y su linea.
 */
class ImportaCalificaciones extends Importador
{
    public function nombre(): string
    {
        return 'Calificaciones';
    }

    public function correr(): void
    {
        /*
         * UNA opinion por atencion, la ULTIMA.
         *
         * En el sistema viejo hay 242 calificaciones para 141 atenciones: la
         * misma clienta califico dos y hasta tres veces la misma visita --
         * volvio a abrir el enlace y lo mando de nuevo. Aca el indice unico
         * (cita, linea) impide guardar dos, y esta bien que lo impida: dos
         * notas de la misma persona sobre el mismo servicio contarian doble
         * en el promedio de la manicurista.
         *
         * Se queda la mas reciente porque es la que la clienta dejo al final,
         * despues de pensarlo.
         *
         * Y se descartan las 52 que no dicen que atencion calificaron: una
         * opinion sin la visita no le dice nada a nadie.
         */
        $filas = $this->legacy('service_ratings')
            ->whereNull('deleted_at')
            ->whereNotNull('employee_service_id')
            ->orderBy('id')
            ->get();

        $ultimaPorAtencion = [];

        foreach ($filas as $fila) {
            $ultimaPorAtencion[(int) $fila->employee_service_id] = $fila;
        }

        $descartadas = $filas->count() - count($ultimaPorAtencion);

        foreach ($ultimaPorAtencion as $fila) {
            $this->una($fila);
        }

        if ($descartadas > 0) {
            $this->reporte->aviso(
                'Calificaciones',
                "{$descartadas} opiniones repetidas sobre la misma atencion: se conservo la mas reciente.",
            );
        }
    }

    private function una(object $fila): void
    {
        $legacyId = (int) $fila->id;

        // Una opinion escrita hace ocho meses no cambia.
        if ($this->map->yaExiste('rating', $legacyId)) {
            $this->reporte->saltado('Calificaciones');

            return;
        }

        $citaId = $this->map->idNuevo('appointment', $fila->employee_service_id);

        if ($citaId === null) {
            /*
             * Sin la atencion no hay donde colgarla. Pasa con las opiniones
             * de atenciones canceladas o borradas, que no se migran: la
             * opinion sin la visita no le dice nada a nadie.
             */
            $this->reporte->saltado('Calificaciones');

            return;
        }

        if ($this->simular) {
            $this->reporte->creado('Calificaciones');

            return;
        }

        $linea = DB::table('appointment_items')
            ->where('appointment_id', $citaId)
            ->orderBy('sort_order')
            ->first(['id', 'resource_id']);

        $cita = DB::table('appointments')->where('id', $citaId)->first(['client_id']);

        $id = ServiceRating::create([
            'business_id' => $this->business->id,
            'appointment_id' => $citaId,
            'appointment_item_id' => $linea?->id,
            'resource_id' => $linea?->resource_id,
            'client_id' => $cita?->client_id,
            'service_rating' => $this->nota($fila->service_calification),
            'staff_rating' => $this->nota($fila->employee_calification),
            'punctuality_rating' => $this->nota($fila->service_punctuality),
            'comment' => trim((string) ($fila->opinion ?? '')) ?: null,
        ])->id;

        $this->map->anotar('rating', $legacyId, $id);
        $this->reporte->creado('Calificaciones');
    }

    /**
     * Una nota utilizable, o null.
     *
     * Fuera de 1 a 5 se descarta en vez de recortarse: un cero guardado por
     * error no es "una estrella", es que nadie califico, y convertirlo en la
     * peor nota posible le bajaria el promedio a alguien por un dato vacio.
     */
    private function nota(mixed $valor): ?int
    {
        $n = (int) $valor;

        return $n >= 1 && $n <= 5 ? $n : null;
    }
}
