<?php

namespace App\Services\Migration\Importadores;

use App\Models\Resource;
use App\Models\ResourceSchedule;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Migration\LegacyMap;
use Illuminate\Support\Facades\DB;

/**
 * El equipo y sus horarios.
 *
 * En el legacy una empleada es un `User`; aca es un `Resource` de tipo staff.
 * La cuenta para entrar al panel NO se crea desde la migracion: contrasenas y
 * permisos los reparte el negocio a mano, y un usuario que nadie pidio es un
 * acceso que nadie recuerda haber dado.
 *
 * QUE SE ACTUALIZA en corridas siguientes: `is_active` y el PORCENTAJE DE
 * COMISION. Alguien que se retira del local tiene que dejar de aparecer en la
 * agenda nueva tambien, y mientras los dos sistemas convivan la nomina se
 * sigue pagando alla -- que es donde el negocio ajusta los acuerdos.
 *
 * Es la misma regla que los precios y los horarios: durante la convivencia el
 * sistema viejo manda. Tener dos fuentes de verdad para lo que gana alguien es
 * peor que tener una incomoda, y descubrirlo el dia de pago es lo peor de
 * todo.
 */
class ImportaEquipo extends Importador
{
    /**
     * Fichas del legacy que son la misma persona.
     *
     * "Alejandra Castillo" existe dos veces: el id 3 con 859 atenciones, y el
     * id 2 con cero (una ficha vieja con el apellido metido en el campo del
     * nombre). Se mapean al MISMO recurso para que, si alguna fila suelta
     * apunta a la ficha vacia, caiga en la persona correcta en vez de crear
     * una segunda Alejandra en la agenda.
     *
     * @var array<int, int> ficha duplicada => ficha buena
     */
    private const MISMA_PERSONA = [
        2 => 3,
    ];

    /** @var array<string, int> ISO-8601: 1 = lunes ... 7 = domingo */
    private const DIAS = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    public function nombre(): string
    {
        return 'Equipo';
    }

    public function correr(): void
    {
        $this->personas();
        $this->fusionarDuplicados();
        $this->horarios();
        $this->quienHaceQue();
    }

    /**
     * Las fichas repetidas, DESPUES de crear a todo el mundo.
     *
     * En una sola pasada esto no funciona: la ficha 2 de Alejandra tiene id
     * menor que la 3, asi que se procesaria antes de que la 3 exista y no
     * habria a quien apuntarla.
     */
    private function fusionarDuplicados(): void
    {
        foreach (self::MISMA_PERSONA as $duplicada => $buena) {
            if ($this->map->yaExiste('resource', $duplicada)) {
                continue;
            }

            $recurso = $this->map->idNuevo('resource', $buena);

            if ($recurso === null) {
                continue;
            }

            $this->anotar('resource', $duplicada, $recurso);
            $this->reporte->aviso(
                'Equipo',
                "La ficha {$duplicada} del sistema viejo es la misma persona que la {$buena}: se fusionaron.",
            );
        }
    }

    private function personas(): void
    {
        /*
         * SIN filtrar `deleted_at`. Dos empleadas borradas del sistema viejo
         * tienen 93 atenciones cobradas entre las dos, y esa plata y esas
         * visitas son reales. Si no se les crea ficha, su historial se cae
         * entero: el reporte de ventas pierde los meses en que trabajaron y
         * las clientas que atendieron pierden visitas.
         *
         * Entran como INACTIVAS, que es lo correcto: existen para que el
         * pasado tenga a quien atribuirse, no para aparecer en la agenda.
         */
        $filas = $this->legacy('users')->orderBy('id')->get();

        foreach ($filas as $fila) {
            $legacyId = (int) $fila->id;

            // Los duplicados se resuelven despues, en una segunda pasada.
            if (isset(self::MISMA_PERSONA[$legacyId])) {
                continue;
            }

            if (! $this->atiende($legacyId)) {
                $this->reporte->saltado('Equipo');

                continue;
            }

            $activo = ((bool) $fila->is_active) && $fila->deleted_at === null;
            $porcentaje = $this->porcentajeGeneral($legacyId, (float) $fila->commission_percentage);
            $huella = LegacyMap::huella([$activo, $porcentaje]);

            $idNuevo = $this->map->idNuevo('resource', $legacyId);

            if ($idNuevo !== null) {
                if ($this->map->cambio('resource', $legacyId, $huella)) {
                    if (! $this->simular) {
                        Resource::withoutGlobalScope('business')
                            ->where('id', $idNuevo)
                            ->update(['is_active' => $activo, 'commission_rate' => $porcentaje]);
                    }

                    $this->anotar('resource', $legacyId, $idNuevo, $huella);
                    $this->reporte->actualizado('Equipo');
                } else {
                    $this->reporte->saltado('Equipo');
                }

                continue;
            }

            $nombre = trim($fila->name.' '.($fila->last_name ?? ''));

            $id = $this->crear(fn () => Resource::create([
                'business_id' => $this->business->id,
                'type' => Resource::TYPE_STAFF,
                'name' => $nombre,
                'is_active' => $activo,
                'is_public' => $activo,
                'is_bookable_online' => $activo,
                'payroll_mode' => 'commission',
                'commission_rate' => $porcentaje,
            ])->id);

            $this->anotar('resource', $legacyId, $id, $huella);
            $this->reporte->creado('Equipo');
        }
    }

    /**
     * El porcentaje general de una persona.
     *
     * NO sale de `users.commission_percentage`: ese campo existe en el sistema
     * viejo pero NO es el que se usa al cobrar. Alla la comision es por
     * (persona, categoria) -- `getEmployeeComissionByType()` -- y una persona
     * puede ir al 50% en manicure y al 60% en pestanas.
     *
     * Aca la cascada es acuerdo puntual > persona > servicio > categoria, asi
     * que el porcentaje de la persona TAPA todo lo de abajo. Por eso se toma
     * el que mas se repite entre sus categorias como su porcentaje general, y
     * las categorias que se salen de ahi se escriben como acuerdo puntual en
     * `quienHaceQue()`.
     *
     * Poner el 50% aqui y confiar en la categoria no funcionaria: la persona
     * gana sobre la categoria, y Nathaly terminaria cobrando 50% en pestanas
     * cuando su acuerdo dice 60%.
     */
    private function porcentajeGeneral(int $legacyId, float $respaldo): float
    {
        $porcentajes = $this->legacy('employee_comissions')
            ->where('employee_id', $legacyId)
            ->whereNull('deleted_at')
            ->pluck('percentage');

        if ($porcentajes->isEmpty()) {
            return round($respaldo / 100, 4);
        }

        $conteo = $porcentajes->map(fn ($p) => (float) $p)->countBy()->sortDesc();

        return round(((float) $conteo->keys()->first()) / 100, 4);
    }

    /**
     * Quien puede prestar que servicio, y a que porcentaje.
     *
     * DOS COSAS, y la primera no es opcional: SIN FILAS EN `service_resource`
     * NADIE PUEDE AGENDAR. `AvailabilityService` busca los recursos capaces a
     * traves de ese pivote, asi que un pivote vacio devuelve cero
     * disponibilidad para todos los servicios y la pagina publica no ofrece un
     * solo horario.
     *
     * El sistema viejo no tiene ese vinculo -- alla cualquiera presta
     * cualquier cosa -- asi que se crean TODOS y el negocio recorta despues.
     * "Lucia no hace acrilicas" es configuracion fina que solo el negocio
     * sabe, y adivinarla dejaria clientas sin poder reservar.
     *
     * Y sobre esas mismas filas se escribe el acuerdo puntual cuando la
     * categoria paga distinto al porcentaje general de la persona.
     */
    private function quienHaceQue(): void
    {
        $servicios = Service::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->get(['id', 'service_category_id']);

        if ($servicios->isEmpty()) {
            return;
        }

        $recursos = Resource::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->where('type', Resource::TYPE_STAFF)
            ->get(['id', 'commission_rate']);

        $acuerdos = $this->acuerdosPorCategoria();

        $existentes = DB::table('service_resource')
            ->whereIn('resource_id', $recursos->pluck('id'))
            ->get()
            ->keyBy(fn ($f) => $f->service_id.'-'.$f->resource_id);

        $nuevas = [];
        $cambiadas = 0;

        foreach ($recursos as $recurso) {
            $general = $recurso->commission_rate === null ? null : (float) $recurso->commission_rate;

            foreach ($servicios as $servicio) {
                $categoria = (int) $servicio->service_category_id;
                $delAcuerdo = $acuerdos[$recurso->id][$categoria] ?? null;

                /*
                 * Solo se escribe cuando se SALE del general. Una fila que
                 * repite el porcentaje de la persona no aporta nada y llenaria
                 * la pantalla de nomina de excepciones que no lo son.
                 */
                $override = ($delAcuerdo !== null && $general !== null
                    && abs($delAcuerdo - $general) > 0.0001) ? $delAcuerdo : null;

                $clave = $servicio->id.'-'.$recurso->id;

                if (isset($existentes[$clave])) {
                    $actual = $existentes[$clave]->commission_rate_override;
                    $actual = $actual === null ? null : (float) $actual;

                    if ($actual !== $override && ! $this->simular) {
                        DB::table('service_resource')
                            ->where('service_id', $servicio->id)
                            ->where('resource_id', $recurso->id)
                            ->update(['commission_rate_override' => $override]);

                        $cambiadas++;
                    }

                    continue;
                }

                $nuevas[] = [
                    'service_id' => $servicio->id,
                    'resource_id' => $recurso->id,
                    'commission_rate_override' => $override,
                ];
            }
        }

        if ($nuevas !== [] && ! $this->simular) {
            foreach (array_chunk($nuevas, 500) as $lote) {
                DB::table('service_resource')->insertOrIgnore($lote);
            }
        }

        $nuevas === []
            ? $this->reporte->saltado('Quien hace que', $existentes->count())
            : $this->reporte->creado('Quien hace que', count($nuevas));

        if ($cambiadas > 0) {
            $this->reporte->actualizado('Quien hace que', $cambiadas);
        }

        $this->avisarLoQueNoCabe($acuerdos, $recursos, $servicios);
    }

    /**
     * Los porcentajes por (recurso, categoria) que trae el sistema viejo.
     *
     * @return array<int, array<int, float>>
     */
    private function acuerdosPorCategoria(): array
    {
        $mapa = [];

        foreach ($this->legacy('employee_comissions')->whereNull('deleted_at')->get() as $fila) {
            $recurso = $this->map->idNuevo('resource', $fila->employee_id);
            $categoria = $this->map->idNuevo('service_category', $fila->service_type_id);

            if ($recurso === null || $categoria === null) {
                continue;
            }

            $mapa[$recurso][$categoria] = round(((float) $fila->percentage) / 100, 4);
        }

        return $mapa;
    }

    /**
     * Acuerdos del sistema viejo que aca no tienen donde aterrizar.
     *
     * Pasa con las categorias SIN SERVICIOS -- el acuerdo existe pero no hay a
     * que aplicarlo -- y con los combos, que aca son paquetes de servicios y
     * no servicios: la comision de un combo sale ahora de sus partes, que
     * pertenecen a otras categorias.
     *
     * No se inventa una equivalencia. Se dice, y el negocio decide.
     *
     * @param  array<int, array<int, float>>  $acuerdos
     */
    private function avisarLoQueNoCabe(array $acuerdos, $recursos, $servicios): void
    {
        $conServicios = $servicios->pluck('service_category_id')->unique()->all();
        $personas = Resource::withoutGlobalScope('business')
            ->whereIn('id', $recursos->pluck('id'))->pluck('name', 'id');
        $categorias = ServiceCategory::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->pluck('name', 'id');

        foreach ($acuerdos as $recursoId => $porCategoria) {
            $general = $recursos->firstWhere('id', $recursoId)?->commission_rate;

            foreach ($porCategoria as $categoriaId => $tasa) {
                if (in_array($categoriaId, $conServicios, true)) {
                    continue;
                }

                // Un acuerdo igual al general no se pierde: es el general.
                if ($general !== null && abs($tasa - (float) $general) <= 0.0001) {
                    continue;
                }

                $pct = round($tasa * 100);
                $quien = $personas[$recursoId] ?? "recurso {$recursoId}";
                $que = $categorias[$categoriaId] ?? "categoria {$categoriaId}";

                $this->reporte->aviso(
                    'Equipo',
                    "{$quien} tiene un acuerdo del {$pct}% en «{$que}», pero esa categoria no tiene "
                    .'servicios activos. No se pudo trasladar: revisar si sigue vigente.',
                );
            }
        }
    }

    /**
     * Quien merece una ficha en la agenda nueva.
     *
     * Atendio alguna vez, o tiene turno asignado. El filtro existe porque en
     * `users` tambien viven el dueno y quien programa: darles ficha llenaria
     * la agenda de columnas que nadie usa, y quitarlas despues obliga a
     * mover las citas que ya se agendaron encima.
     *
     * Se pregunta por historial ADEMAS de por turno porque quien ya no
     * trabaja alli no tiene turnos, pero sus 738 atenciones si tienen que
     * quedar atribuidas a alguien.
     */
    private function atiende(int $legacyId): bool
    {
        $atendio = $this->legacy('employee_services')
            ->where('employee_id', $legacyId)
            ->where('status_id', 2)
            ->exists();

        if ($atendio) {
            return true;
        }

        return $this->legacy('work_shifts')
            ->where('user_id', $legacyId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Los horarios, RECONCILIADOS contra el sistema viejo.
     *
     * No basta con agregar los turnos nuevos. Mientras los dos sistemas
     * conviven, el negocio sigue administrando sus turnos alla -- y de hecho
     * lo hizo a mitad de esta migracion: de 18 turnos paso a 5. Un paso que
     * solo agrega dejaria al motor ofreciendo trece franjas que ya nadie
     * trabaja, y la agenda nueva se llenaria de citas a horas en que el local
     * esta cerrado.
     *
     * Por eso este paso es el UNICO que quita: para cada persona, las
     * ventanas que ya no existen alla se CIERRAN aca. Cerrar y no borrar,
     * porque una cita agendada la semana pasada dentro de esa franja tiene
     * que seguir explicandose.
     *
     * La contrapartida honesta: si alguien ajusta un horario en el sistema
     * nuevo, la corrida siguiente se lo devuelve. Mientras dure la
     * convivencia los turnos se editan en el sistema viejo, y punto -- tener
     * dos fuentes de verdad para el horario es peor que tener una incomoda.
     *
     * Un turno del legacy es una franja + una lista de dias, asi que uno solo
     * produce hasta siete ventanas aca. Y una misma persona puede tener dos
     * turnos el mismo dia (manana y tarde): son dos ventanas, y el motor las
     * soporta.
     */
    private function horarios(): void
    {
        $deseadas = $this->ventanasDelLegacy();
        $recursos = $this->recursosConTurnos();

        foreach ($recursos as $recurso) {
            $this->reconciliar($recurso, $deseadas[$recurso] ?? []);
        }
    }

    /**
     * Las ventanas que el sistema viejo tiene HOY, por recurso.
     *
     * @return array<int, list<array{weekday:int, start:string, end:string}>>
     */
    private function ventanasDelLegacy(): array
    {
        $mapa = [];

        $turnos = $this->legacy('work_shifts')->whereNull('deleted_at')->orderBy('id')->get();

        foreach ($turnos as $turno) {
            $recurso = $this->map->idNuevo('resource', $turno->user_id);

            if ($recurso === null) {
                continue;
            }

            $dias = json_decode((string) $turno->days, true);

            if (! is_array($dias) || $dias === []) {
                $this->reporte->aviso('Horarios', "El turno {$turno->id} no tiene dias.");

                continue;
            }

            foreach ($dias as $dia) {
                $iso = self::DIAS[strtolower((string) $dia)] ?? null;

                if ($iso === null) {
                    $this->reporte->aviso('Horarios', "Dia desconocido en el turno {$turno->id}: {$dia}");

                    continue;
                }

                $mapa[$recurso][] = [
                    'weekday' => $iso,
                    'start' => substr((string) $turno->start_time, 0, 5),
                    'end' => substr((string) $turno->end_time, 0, 5),
                ];
            }
        }

        return $mapa;
    }

    /**
     * Los recursos que alguna vez tuvieron turno alla.
     *
     * Solo esos se reconcilian. Un recurso creado a mano en el sistema nuevo,
     * que el viejo no conoce, no puede quedarse sin horario porque una
     * migracion decidio que "alla no existe".
     *
     * @return list<int>
     */
    private function recursosConTurnos(): array
    {
        $usuarios = $this->legacy('work_shifts')->distinct()->pluck('user_id');

        $recursos = [];

        foreach ($usuarios as $usuario) {
            $id = $this->map->idNuevo('resource', $usuario);

            if ($id !== null) {
                $recursos[$id] = true;
            }
        }

        return array_keys($recursos);
    }

    /**
     * Deja las ventanas de un recurso iguales a las del sistema viejo.
     *
     * @param  list<array{weekday:int, start:string, end:string}>  $deseadas
     */
    private function reconciliar(int $recurso, array $deseadas): void
    {
        $vigentes = ResourceSchedule::withoutGlobalScope('business')
            ->where('resource_id', $recurso)
            ->whereNull('effective_to')
            ->get();

        $clave = fn (int $dia, string $desde, string $hasta) => "{$dia}|{$desde}|{$hasta}";

        $existentes = [];

        foreach ($vigentes as $v) {
            $existentes[$clave(
                (int) $v->weekday,
                substr((string) $v->start_time, 0, 5),
                substr((string) $v->end_time, 0, 5),
            )] = $v;
        }

        $creadas = 0;

        foreach ($deseadas as $d) {
            $k = $clave($d['weekday'], $d['start'], $d['end']);

            if (isset($existentes[$k])) {
                // Ya esta y sigue vigente: se quita de la lista de sobrantes.
                unset($existentes[$k]);

                continue;
            }

            $this->crear(fn () => ResourceSchedule::create([
                'business_id' => $this->business->id,
                'resource_id' => $recurso,
                'weekday' => $d['weekday'],
                'start_time' => $d['start'],
                'end_time' => $d['end'],
                /*
                 * Desde hoy y no desde una fecha vieja: una ventana que se
                 * agrega hoy no puede reescribir la disponibilidad del mes
                 * pasado, donde ya hay citas cobradas que se explican con el
                 * horario que habia entonces.
                 */
                'effective_from' => now($this->business->businessTimezone())->toDateString(),
            ]));

            $creadas++;
        }

        if ($creadas > 0) {
            $this->reporte->creado('Horarios', $creadas);
        }

        // Lo que quedo en `$existentes` ya no esta en el sistema viejo.
        if ($existentes === []) {
            $this->reporte->saltado('Horarios', count($deseadas));

            return;
        }

        if (! $this->simular) {
            ResourceSchedule::withoutGlobalScope('business')
                ->whereIn('id', array_map(fn ($v) => $v->id, $existentes))
                ->update(['effective_to' => now($this->business->businessTimezone())->toDateString()]);
        }

        $this->reporte->actualizado('Horarios', count($existentes));
        $this->reporte->aviso(
            'Horarios',
            count($existentes).' franjas se cerraron porque ya no existen en el sistema viejo.',
        );
    }

    private function anotar(string $entidad, int $legacyId, int $nuevoId, ?string $huella = null): void
    {
        $this->simular
            ? $this->map->anotarEnMemoria($entidad, $legacyId, $nuevoId, $huella)
            : $this->map->anotar($entidad, $legacyId, $nuevoId, $huella);
    }
}
