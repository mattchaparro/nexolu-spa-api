<?php

namespace App\Services\Migration\Importadores;

use App\Models\Resource;
use App\Models\ResourceSchedule;
use App\Services\Migration\LegacyMap;

/**
 * El equipo y sus horarios.
 *
 * En el legacy una empleada es un `User`; aca es un `Resource` de tipo staff.
 * La cuenta para entrar al panel NO se crea desde la migracion: contrasenas y
 * permisos los reparte el negocio a mano, y un usuario que nadie pidio es un
 * acceso que nadie recuerda haber dado.
 *
 * QUE SE ACTUALIZA en corridas siguientes: solo `is_active`. Alguien que se
 * retira del local tiene que dejar de aparecer en la agenda nueva tambien.
 * El porcentaje de comision NO se re-sincroniza: es lo primero que el negocio
 * va a ajustar aca, y pisarlo cada noche seria devolverle el cambio sin
 * avisar.
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
            $huella = LegacyMap::huella([$activo]);

            $idNuevo = $this->map->idNuevo('resource', $legacyId);

            if ($idNuevo !== null) {
                if ($this->map->cambio('resource', $legacyId, $huella)) {
                    if (! $this->simular) {
                        Resource::withoutGlobalScope('business')
                            ->where('id', $idNuevo)
                            ->update(['is_active' => $activo]);
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
                'commission_rate' => round(((float) $fila->commission_percentage) / 100, 4),
            ])->id);

            $this->anotar('resource', $legacyId, $id, $huella);
            $this->reporte->creado('Equipo');
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
