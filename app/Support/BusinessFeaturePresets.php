<?php

namespace App\Support;

/**
 * Feature flags por plan y por vertical.
 *
 * Un negocio nunca lee esto directo: lee Business::resolvedFeatureFlags(), que
 * mezcla estos defaults con lo que el negocio tenga explicito. El front lee el
 * resultado ya resuelto y no reimplementa la mezcla, para que las dos copias
 * no se desincronicen.
 */
class BusinessFeaturePresets
{
    public const VERTICAL_SPA_UNAS = 'spa_unas';

    public const VERTICAL_BARBERIA = 'barberia';

    public const VERTICAL_ESTETICA = 'estetica';

    public const PLAN_BASICO = 'basico';

    public const PLAN_PRO = 'pro';

    public const PLAN_FULL = 'full';

    /**
     * Catalogo completo de flags. Agregar uno es tocar este array y nada mas.
     *
     * @return list<string>
     */
    public static function catalog(): array
    {
        return [
            'scheduling',            // agenda y citas
            'online_booking',        // reserva publica sin autenticar
            'whatsapp_agent',        // agente de IA por WhatsApp
            'reminders',             // recordatorios automaticos
            'clients',
            'client_history',        // historial y fotos por cliente
            'multi_resource',        // servicios que ocupan mas de un recurso
            'commissions',
            'cash_shift',            // turnos de caja
            'cash_closing',
            'managerial_accounting',
            'expenses',
            'product_sales',         // venta simple de producto
            'loyalty',               // sellos y recompensas
            'promotions',
            'no_show_penalties',
            'permissions_management',
            'audit_logs',
            'reports',
        ];
    }

    /**
     * @return array<string, bool>
     *
     * `cash_shift` queda APAGADO por defecto. En un spa nadie abre y cierra
     * caja por turnos: las profesionales registran sus servicios y lo que
     * importa es que el cierre del dia cuadre contra lo que reportaron. El
     * turno existe para el negocio que si tenga una cajera dedicada, pero no
     * es el caso normal y encenderlo por defecto obliga a todos a un ritual
     * que no hacen.
     */
    public static function basico(): array
    {
        return array_merge(self::none(), [
            'scheduling' => true,
            'clients' => true,
            'reminders' => true,
            'cash_closing' => true,
            'reports' => true,
        ]);
    }

    /** @return array<string, bool> */
    public static function pro(): array
    {
        return array_merge(self::basico(), [
            'online_booking' => true,
            'client_history' => true,
            'commissions' => true,
            'expenses' => true,
            'product_sales' => true,
            'promotions' => true,
            'no_show_penalties' => true,
            'audit_logs' => true,
        ]);
    }

    /**
     * @return array<string, bool>
     *
     * Full tampoco enciende `cash_shift`: no es una funcion "avanzada" sino
     * una forma distinta de operar la caja. Se enciende negocio por negocio.
     */
    public static function full(): array
    {
        return array_merge(
            array_map(fn () => true, array_flip(self::catalog())),
            ['cash_shift' => false],
        );
    }

    /** @return array<string, bool> */
    public static function fromPlan(?string $plan): array
    {
        return match ($plan) {
            self::PLAN_BASICO => self::basico(),
            self::PLAN_PRO => self::pro(),
            self::PLAN_FULL => self::full(),
            // Sin plan asignado se asume todo habilitado, igual que el POS
            // hace con los negocios anteriores a los feature flags.
            default => self::full(),
        };
    }

    /**
     * Ajustes iniciales segun la vertical. No cambian que puede contratar el
     * negocio, solo con que arranca configurado.
     *
     * @return array<string, bool>
     */
    public static function fromVertical(string $vertical): array
    {
        return match ($vertical) {
            // Una barberia rara vez necesita cabinas ni servicios que ocupen
            // dos recursos a la vez.
            self::VERTICAL_BARBERIA => ['multi_resource' => false],

            // Estetica casi siempre si: cabinas, camillas, equipos.
            self::VERTICAL_ESTETICA => ['multi_resource' => true],

            default => [],
        };
    }

    /** @return list<string> */
    public static function verticals(): array
    {
        return [self::VERTICAL_SPA_UNAS, self::VERTICAL_BARBERIA, self::VERTICAL_ESTETICA];
    }

    /** @return array<string, bool> */
    private static function none(): array
    {
        return array_map(fn () => false, array_flip(self::catalog()));
    }
}
