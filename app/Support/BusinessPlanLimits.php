<?php

namespace App\Support;

/**
 * Cuanto puede tener un negocio segun su plan.
 *
 * El otro eje de la separacion de planes. `BusinessFeaturePresets` decide QUE
 * modulos ve un negocio; esto decide CUANTO puede cargar dentro de ellos. Sin
 * esta mitad, los tres planes se diferencian solo en funciones y nada impide
 * que un negocio del plan mas barato cargue treinta personas en la agenda.
 *
 * Mismo patron que los feature flags, y a proposito: preset por plan +
 * excepciones explicitas por negocio (`businesses.plan_limits`). Asi se le
 * puede vender una excepcion a un negocio sin inventar un plan nuevo para el.
 *
 * DOS REGLAS QUE NO SON OBVIAS:
 *
 * 1. Se cuenta solo lo ACTIVO. Desactivar a alguien libera el cupo. Si
 *    contaramos los inactivos, un local que rota personal chocaria contra un
 *    tope que no puede explicarse -- "tengo 4 personas y me dice que llegue a
 *    5" es una llamada a soporte garantizada.
 *
 * 2. Se valida al CREAR, nunca hacia atras. Un negocio que quede por encima
 *    de su tope (porque bajo de plan, o porque le cambiaron la excepcion)
 *    sigue operando con lo que ya tiene: solo no puede agregar mas. Bloquearle
 *    la agenda por una decision comercial seria romperle el dia de trabajo
 *    para cobrarle.
 */
class BusinessPlanLimits
{
    /** Sin tope. */
    public const UNLIMITED = null;

    public const MAX_RESOURCES = 'max_resources';

    /**
     * Catalogo completo de topes. Agregar uno es tocar este archivo y el
     * contador que lo mide, nada mas.
     *
     * NO hay topes de uso -- citas por mes, clientes en la base. Un tope asi
     * se agota justo cuando al negocio le esta yendo bien, y deja a una agenda
     * sin poder agendar: el producto falla en lo unico que hace, el dia que
     * mas lo necesitan. Lo que se cobra es capacidad instalada, no actividad.
     *
     * @return list<string>
     */
    public static function catalog(): array
    {
        return [self::MAX_RESOURCES];
    }

    /**
     * Como se le explica cada tope a quien configura un negocio.
     *
     * @return array<string, array{label: string, help: string, unit: string}>
     */
    public static function labels(): array
    {
        return [
            self::MAX_RESOURCES => [
                'label' => 'Personas en la agenda',
                'help' => 'Cuánta gente puede atender citas a la vez. Se cuentan sólo las activas: '
                    .'desactivar a alguien libera el cupo.',
                'unit' => 'personas',
            ],
        ];
    }

    /**
     * @return array<string, int|null>
     *
     * Los numeros son una decision COMERCIAL, no tecnica: se cambian aca sin
     * tocar nada mas.
     */
    public static function basico(): array
    {
        return [self::MAX_RESOURCES => 3];
    }

    /** @return array<string, int|null> */
    public static function pro(): array
    {
        return [self::MAX_RESOURCES => 10];
    }

    /** @return array<string, int|null> */
    public static function full(): array
    {
        return [self::MAX_RESOURCES => self::UNLIMITED];
    }

    /** @return array<string, int|null> */
    public static function fromPlan(?string $plan): array
    {
        return match ($plan) {
            BusinessFeaturePresets::PLAN_BASICO => self::basico(),
            BusinessFeaturePresets::PLAN_PRO => self::pro(),
            BusinessFeaturePresets::PLAN_FULL => self::full(),
            /*
             * Sin plan asignado, sin topes. Es el mismo criterio que
             * `BusinessFeaturePresets::fromPlan()` usa para las funciones, y
             * la unica opcion segura: un negocio anterior a esta separacion no
             * puede quedarse sin poder agregar gente porque le falte un campo
             * que nadie le lleno.
             */
            default => self::full(),
        };
    }

    /**
     * Si cabe uno mas.
     *
     * `$current` es cuantos hay ACTIVOS hoy. Un tope nulo es sin limite.
     */
    public static function allows(?int $limit, int $current): bool
    {
        return $limit === null || $current < $limit;
    }

    /**
     * El catalogo con su etiqueta, para el panel de superadmin.
     *
     * Un tope nuevo sin etiqueta igual aparece, con su llave cruda. Es feo a
     * proposito: se nota y se corrige, en vez de desaparecer del panel sin que
     * nadie lo note.
     *
     * @return list<array{key: string, label: string, help: string, unit: string}>
     */
    public static function describedCatalog(): array
    {
        $labels = self::labels();

        return array_map(
            fn (string $key) => ['key' => $key] + ($labels[$key] ?? [
                'label' => $key,
                'help' => '',
                'unit' => '',
            ]),
            self::catalog(),
        );
    }
}
