<?php

namespace App\Services\Migration;

use App\Models\Business;
use App\Models\LoyaltyReward;
use App\Models\Resource;
use App\Models\Service;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compara las dos bases y dice en que NO se parecen.
 *
 * Existe porque los huecos de esta migracion se estaban encontrando por
 * accidente: uno corria el importador, miraba una cifra por curiosidad, y
 * aparecia que 159 atenciones eran combos o que el pivote de servicios estaba
 * vacio. Eso funciona hasta que deja de funcionar, y el dia que deja de
 * funcionar los datos ya estan en produccion.
 *
 * Cada comprobacion de aca nacio de un hueco real. La lista es, literalmente,
 * la lista de lo que ya salio mal una vez.
 *
 * NO ARREGLA NADA. Compara y reporta. Arreglar es volver a correr el
 * importador -- que es idempotente -- o corregir el codigo y volver a
 * correrlo. Una auditoria que ademas repara es una auditoria en la que uno
 * deja de confiar.
 */
class Auditoria
{
    private const ZONA = 'America/Bogota';

    /** @var list<array{grupo:string, que:string, esperado:string, obtenido:string, ok:bool, nota:?string}> */
    private array $resultados = [];

    public function __construct(private readonly Business $business) {}

    /** @return list<array{grupo:string, que:string, esperado:string, obtenido:string, ok:bool, nota:?string}> */
    public function correr(): array
    {
        $this->conteos();
        $this->dinero();
        $this->integridad();
        $this->reglas();
        $this->loQueDebeSerCero();

        return $this->resultados;
    }

    public function fallas(): int
    {
        return count(array_filter($this->resultados, fn (array $r) => ! $r['ok']));
    }

    /*
    |--------------------------------------------------------------------------
    | ¿Falta algo?
    |--------------------------------------------------------------------------
    */

    private function conteos(): void
    {
        $es = fn (string $t) => DB::connection('legacy')->table($t);

        $this->comparar(
            'Conteos', 'Atenciones cobradas',
            $es('employee_services')->where('status_id', 2)->whereNull('deleted_at')->count(),
            $this->mapeadas('appointment', 'completed'),
            'Cada atencion cobrada del sistema viejo tiene que ser una cita terminada aca.',
        );

        $this->comparar(
            'Conteos', 'Fichas de clienta',
            $es('clients')->whereNull('deleted_at')->count(),
            DB::table('legacy_map')->where('business_id', $this->business->id)
                ->where('entity', 'client')->count(),
            'Se cuentan FICHAS, no clientas: varias fichas pueden apuntar a la misma persona.',
        );

        $this->comparar(
            'Conteos', 'Servicios activos',
            $es('services')->whereNull('deleted_at')->count(),
            DB::table('legacy_map')->where('business_id', $this->business->id)
                ->whereIn('entity', ['service', 'service_package'])->count(),
            'Servicios + combos: los combos aca son paquetes.',
        );

        $this->comparar(
            'Conteos', 'Calificaciones',
            $es('service_ratings')->whereNull('deleted_at')->whereNotNull('employee_service_id')
                ->distinct()->count('employee_service_id'),
            DB::table('service_ratings')->where('business_id', $this->business->id)->count(),
            'Una por atencion: en el origen la misma clienta califico la misma visita varias veces.',
        );

        $this->comparar(
            'Conteos', 'Citas agendadas a futuro',
            $es('employee_services')->where('status_id', 4)->whereNull('deleted_at')
                ->whereDate('date', '>=', CarbonImmutable::now(self::ZONA)->toDateString())->count(),
            DB::table('appointments')->where('business_id', $this->business->id)
                ->where('status', 'pending')->count(),
            'Si sobran aca, alguna se cancelo alla y no se reconcilio.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ¿Cuadra la plata?
    |--------------------------------------------------------------------------
    */

    private function dinero(): void
    {
        $anios = DB::connection('legacy')->table('employee_services')
            ->where('status_id', 2)->whereNull('deleted_at')
            ->selectRaw('YEAR(finished_at) anio, SUM(final_price) ingresos, SUM(commission) comision')
            ->groupBy('anio')->orderBy('anio')->get();

        foreach ($anios as $fila) {
            if ($fila->anio === null) {
                continue;
            }

            $migrado = DB::table('appointments')
                ->where('business_id', $this->business->id)
                ->where('status', 'completed')
                ->whereRaw("YEAR(CONVERT_TZ(checked_out_at,'+00:00','-05:00')) = ?", [$fila->anio])
                ->selectRaw('SUM(total) ingresos, SUM(commission_total) comision')
                ->first();

            $this->comparar(
                'Dinero', "Ingresos {$fila->anio}",
                (int) round((float) $fila->ingresos),
                (int) round((float) ($migrado->ingresos ?? 0)),
                'Al peso. Una diferencia aca es plata que el reporte va a mostrar mal.',
            );

            $this->comparar(
                'Dinero', "Comisiones {$fila->anio}",
                (int) round((float) $fila->comision),
                (int) round((float) ($migrado->comision ?? 0)),
                'Es lo que se le pago al equipo: una diferencia sale en la nomina.',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ¿Esta sana la base nueva?
    |--------------------------------------------------------------------------
    */

    private function integridad(): void
    {
        $this->debeSerCero(
            'Integridad', 'Citas sin lineas',
            DB::table('appointments as a')
                ->where('a.business_id', $this->business->id)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('appointment_items as i')
                    ->whereColumn('i.appointment_id', 'a.id'))
                ->count(),
            'Una cita sin lineas suma su total en el reporte y no dice que se hizo. Ya paso una vez.',
        );

        $this->debeSerCero(
            'Integridad', 'Citas cuyo total no es la suma de sus lineas',
            DB::table('appointments as a')
                ->join('appointment_items as i', 'i.appointment_id', '=', 'a.id')
                ->where('a.business_id', $this->business->id)
                // `select` explicito: `get()` sobre un GROUP BY pide `select *`
                // y MySQL con `only_full_group_by` lo rechaza.
                ->select('a.id')
                ->groupBy('a.id', 'a.total')
                ->havingRaw('ABS(a.total - SUM(i.final_price)) > 0.01')
                ->get()->count(),
            'Reparte mal la plata de un combo entre sus partes.',
        );

        $this->debeSerCero(
            'Integridad', 'Citas que la migracion no reconoce',
            DB::table('appointments as a')
                ->where('a.business_id', $this->business->id)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('legacy_map as m')
                    ->where('m.business_id', $this->business->id)
                    ->where('m.entity', 'appointment')
                    ->whereColumn('m.new_id', 'a.id'))
                ->count(),
            'Citas creadas por fuera del importador. En modo sombra deberian ser cero.',
        );

        $this->debeSerCero(
            'Integridad', 'Citas terminadas que siguen ocupando horario',
            DB::table('resource_occupancy as o')
                ->join('appointment_items as i', 'i.id', '=', 'o.appointment_item_id')
                ->join('appointments as a', 'a.id', '=', 'i.appointment_id')
                ->where('a.business_id', $this->business->id)
                ->where('a.status', 'completed')
                ->count(),
            'Una cita del pasado que sigue bloqueando un cupo mata ese horario para siempre.',
        );

        $this->debeSerCero(
            'Integridad', 'Clientas repetidas por telefono',
            DB::table('clients')
                ->where('business_id', $this->business->id)
                ->whereNotNull('phone')
                ->select('phone')
                ->groupBy('phone')->havingRaw('COUNT(*) > 1')
                ->get()->count(),
            'Dos fichas con la misma linea parten el historial de esa persona en dos.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ¿Se respetó lo que se decidió?
    |--------------------------------------------------------------------------
    */

    private function reglas(): void
    {
        // Sellos: uno por visita atribuible, ni mas ni menos.
        $this->comparar(
            'Reglas', 'Sellos',
            DB::table('appointments')->where('business_id', $this->business->id)
                ->where('status', 'completed')->whereNotNull('client_id')->count(),
            DB::table('loyalty_stamps')->where('business_id', $this->business->id)->count(),
            'Un sello por visita con clienta. De mas o de menos cambia quien tiene premio.',
        );

        // Los premios que el sistema viejo prometio y nadie canjeo.
        $this->comparar(
            'Reglas', 'Premios sin canjear',
            DB::connection('legacy')->table('loyalty_card_rewards')
                ->where('status', 'available')->count(),
            DB::table('loyalty_rewards')->where('business_id', $this->business->id)
                ->where('status', LoyaltyReward::STATUS_AVAILABLE)->count(),
            'Son promesas ya hechas a clientas reales. Si faltan, alguien se queda sin su descuento.',
        );

        // El pivote: sin el, nadie puede agendar.
        $sinServicios = Resource::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->where('type', Resource::TYPE_STAFF)
            ->where('is_active', true)
            ->whereDoesntHave('services')
            ->count();

        $this->debeSerCero(
            'Reglas', 'Personas activas que no pueden prestar ningun servicio',
            $sinServicios,
            'Sin el vinculo servicio-persona la agenda devuelve cero huecos. Ya paso.',
        );

        $this->comisiones();
        $this->horarios();
        $this->disponibilidad();
    }

    /**
     * La comision que resuelve la cascada tiene que ser la del sistema viejo.
     *
     * Se comprueba servicio por servicio y persona por persona, no un
     * promedio: el error que se busca es exactamente el que tuvimos -- el
     * porcentaje de la persona tapando el de la categoria, y la excepcion de
     * una manicurista desapareciendo sin que nada se rompa.
     */
    private function comisiones(): void
    {
        $malas = 0;
        $ejemplo = null;

        $acuerdos = DB::connection('legacy')->table('employee_comissions')->whereNull('deleted_at')->get();

        $servicios = Service::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->get(['id', 'service_category_id', 'name'])
            ->groupBy('service_category_id');

        foreach ($acuerdos as $acuerdo) {
            $recursoId = $this->idNuevo('resource', $acuerdo->employee_id);
            $categoriaId = $this->idNuevo('service_category', $acuerdo->service_type_id);

            if ($recursoId === null || $categoriaId === null) {
                continue;
            }

            $recurso = Resource::withoutGlobalScope('business')->find($recursoId);

            foreach ($servicios[$categoriaId] ?? [] as $servicio) {
                $tasa = $servicio->commissionRateFor($recurso);
                $esperada = round(((float) $acuerdo->percentage) / 100, 4);

                if ($tasa === null || abs($tasa - $esperada) > 0.0001) {
                    $malas++;
                    $ejemplo ??= "{$recurso?->name} en «{$servicio->name}»: "
                        .'esperado '.round($esperada * 100).'%, resuelve '
                        .($tasa === null ? 'nada' : round($tasa * 100).'%');
                }
            }
        }

        $this->debeSerCero(
            'Reglas', 'Comisiones que no resuelven como en el sistema viejo',
            $malas,
            $ejemplo ?? 'Cada combinacion persona-servicio da el mismo porcentaje que alla.',
        );
    }

    private function horarios(): void
    {
        $turnos = DB::connection('legacy')->table('work_shifts')->whereNull('deleted_at')->get();

        $ventanas = 0;

        foreach ($turnos as $turno) {
            $dias = json_decode((string) $turno->days, true);
            $ventanas += is_array($dias) ? count($dias) : 0;
        }

        $this->comparar(
            'Reglas', 'Franjas de horario vigentes',
            $ventanas,
            DB::table('resource_schedules')->where('business_id', $this->business->id)
                ->whereNull('effective_to')->count(),
            'De mas = se ofrecen horas en que el local esta cerrado. De menos = no se puede agendar.',
        );
    }

    /**
     * Que la agenda de verdad ofrezca horarios.
     *
     * Es la comprobacion menos elegante y la mas util: todo lo demas puede
     * cuadrar y la pagina publica seguir vacia, que fue exactamente lo que
     * paso con el pivote de servicios.
     */
    private function disponibilidad(): void
    {
        $servicio = Service::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($servicio === null) {
            $this->anotar('Reglas', 'Huecos libres esta semana', '> 0', 'sin servicios', false, 'No hay servicios activos.');

            return;
        }

        $huecos = 0;

        try {
            $hoy = CarbonImmutable::now(self::ZONA)->startOfDay();

            for ($i = 0; $i < 7 && $huecos === 0; $i++) {
                $huecos = count(app(AvailabilityService::class)
                    ->slotsForService($this->business, $servicio, $hoy->addDays($i)));
            }
        } catch (Throwable $e) {
            $this->anotar('Reglas', 'Huecos libres esta semana', '> 0', 'error', false, $e->getMessage());

            return;
        }

        $this->anotar(
            'Reglas', 'Huecos libres esta semana', '> 0', (string) $huecos, $huecos > 0,
            "Con «{$servicio->name}». En cero, la pagina publica no ofrece nada.",
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que nunca debe pasar
    |--------------------------------------------------------------------------
    */

    private function loQueDebeSerCero(): void
    {
        /*
         * En el sistema viejo hay usuarios con rol `client`: clientas que se
         * crearon un acceso para ver su tarjeta. Si alguna termino con cuenta
         * en el panel del negocio, eso es una fuga y no un detalle.
         */
        $correosDeClientas = DB::connection('legacy')->table('users')
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'client')
            ->pluck('users.email')
            ->filter()
            ->all();

        $this->debeSerCero(
            'Nunca', 'Clientas con cuenta en el panel',
            $correosDeClientas === [] ? 0 : DB::table('users')
                ->where('business_id', $this->business->id)
                ->whereIn('email', $correosDeClientas)->count(),
            'Una clienta con acceso al panel ve la base de clientas entera.',
        );

        $this->debeSerCero(
            'Nunca', 'Cuentas del equipo sin rol',
            DB::table('users as u')
                ->where('u.business_id', $this->business->id)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('model_has_roles as r')
                    ->whereColumn('r.model_id', 'u.id'))
                ->count(),
            'Una cuenta sin rol entra y no ve nada, o peor, depende de un default.',
        );

        $this->debeSerCero(
            'Nunca', 'Citas de dos personas a la misma hora',
            DB::table('resource_occupancy')
                ->where('business_id', $this->business->id)
                ->select('resource_id')
                ->groupBy('resource_id', 'slot_start')
                ->havingRaw('COUNT(*) > 1')
                ->get()->count(),
            'El indice unico lo impide, pero si alguna vez fallara habria que saberlo aca.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Plomeria
    |--------------------------------------------------------------------------
    */

    private function mapeadas(string $entity, string $status): int
    {
        return DB::table('legacy_map as m')
            ->join('appointments as a', 'a.id', '=', 'm.new_id')
            ->where('m.business_id', $this->business->id)
            ->where('m.entity', $entity)
            ->where('a.status', $status)
            ->count();
    }

    private function idNuevo(string $entity, int|string|null $legacyId): ?int
    {
        if ($legacyId === null) {
            return null;
        }

        $id = DB::table('legacy_map')
            ->where('business_id', $this->business->id)
            ->where('entity', $entity)
            ->where('legacy_id', $legacyId)
            ->value('new_id');

        return $id === null ? null : (int) $id;
    }

    private function comparar(string $grupo, string $que, int $esperado, int $obtenido, ?string $nota = null): void
    {
        $this->anotar($grupo, $que, number_format($esperado, 0, ',', '.'), number_format($obtenido, 0, ',', '.'), $esperado === $obtenido, $nota);
    }

    private function debeSerCero(string $grupo, string $que, int $obtenido, ?string $nota = null): void
    {
        $this->anotar($grupo, $que, '0', (string) $obtenido, $obtenido === 0, $nota);
    }

    private function anotar(string $grupo, string $que, string $esperado, string $obtenido, bool $ok, ?string $nota): void
    {
        $this->resultados[] = compact('grupo', 'que', 'esperado', 'obtenido', 'ok', 'nota');
    }
}
