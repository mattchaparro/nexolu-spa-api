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

    /**
     * Sobre cuanto pregunta cada cosa la encuesta VIEJA.
     *
     * No las tres sobre cinco: la encuesta de ManyChat ofrecia distinta
     * cantidad de botones por pregunta, y se ve en los datos -- en 242
     * opiniones nunca hay un 5 en servicio ni un 4 en puntualidad:
     *
     *     atencion      1 a 5   (226 de 242 pusieron 5)
     *     servicio      1 a 4   (237 de 242 pusieron 4)
     *     puntualidad   1 a 3   (221 de 242 pusieron 3)
     *
     * La encuesta propia pregunta las tres sobre 5, asi que sin guardar esto
     * una nota vieja y una nueva no se pueden comparar: un 3 de 3 es lo mejor
     * que se puede dar y un 3 de 5 es un reclamo.
     */
    private const ESCALAS = ['service' => 4, 'staff' => 5, 'punctuality' => 3];

    /**
     * Arregla en el sitio una opinion que ya se importo mal.
     *
     * Las primeras corridas guardaron `created_at` con la fecha de la
     * migracion y sin escala. Se corrige aca y no con un comando suelto para
     * que quede reparado solo en la siguiente sincronizacion, y para que no
     * haya un parche que alguien tenga que acordarse de correr.
     *
     * Solo toca lo que esta mal: si la fila ya quedo bien, no se escribe.
     */
    private function repararSiHaceFalta(int $legacyId, object $fila): void
    {
        $id = $this->map->idNuevo('rating', $legacyId);

        if ($id === null || $this->simular) {
            $this->reporte->saltado('Calificaciones');

            return;
        }

        $nota = ServiceRating::withoutGlobalScope('business')->find($id);
        $cuando = $this->utc($fila->created_at);

        if ($nota === null || $cuando === null) {
            $this->reporte->saltado('Calificaciones');

            return;
        }

        $correcto = [
            'service_scale' => self::ESCALAS['service'],
            'staff_scale' => self::ESCALAS['staff'],
            'punctuality_scale' => self::ESCALAS['punctuality'],
            'created_at' => $cuando,
        ];

        $cambia = (int) $nota->service_scale !== $correcto['service_scale']
            || (int) $nota->staff_scale !== $correcto['staff_scale']
            || (int) $nota->punctuality_scale !== $correcto['punctuality_scale']
            || $nota->created_at?->ne($cuando);

        if (! $cambia) {
            $this->reporte->saltado('Calificaciones');

            return;
        }

        // `forceFill` + `timestamps=false`: se esta escribiendo `created_at` a
        // proposito, y dejar que Eloquent toque `updated_at` seria volver a
        // poner la fecha de hoy en la fila que se acaba de arreglar.
        $nota->timestamps = false;
        $nota->forceFill($correcto)->save();

        $this->reporte->actualizado('Calificaciones');
    }

    private function una(object $fila): void
    {
        $legacyId = (int) $fila->id;

        // Una opinion escrita hace ocho meses no cambia. Pero lo que se
        // GUARDO de ella si puede estar mal, y entonces hay que repararlo.
        if ($this->map->yaExiste('rating', $legacyId)) {
            $this->repararSiHaceFalta($legacyId, $fila);

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

            // Sobre cuanto fue cada una. Ver `ESCALAS`.
            'service_scale' => self::ESCALAS['service'],
            'staff_scale' => self::ESCALAS['staff'],
            'punctuality_scale' => self::ESCALAS['punctuality'],

            /*
             * LA FECHA EN QUE LA CLIENTA OPINO, no la de la migracion.
             *
             * Sin esto Eloquent estampa `now()` y los siete meses de opiniones
             * -- del 19 de febrero al 5 de septiembre -- se aplastan todos al
             * dia en que se corrio la importacion. Deja de haber historia: no
             * se puede ver si alguien viene mejorando, que es justamente para
             * lo que una profesional mira sus calificaciones.
             */
            'created_at' => $this->utc($fila->created_at),
            'updated_at' => $this->utc($fila->updated_at ?? $fila->created_at),
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
