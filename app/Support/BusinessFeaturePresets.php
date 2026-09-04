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
            'multi_location',        // varias sedes bajo un mismo negocio
            'online_booking',        // reserva publica sin autenticar
            'whatsapp_agent',        // agente de IA por WhatsApp
            'reminders',             // recordatorios automaticos
            'clients',
            'client_history',        // historial y fotos por cliente
            'multi_resource',        // servicios que ocupan mas de un recurso
            'commissions',
            'payroll',               // liquidacion de pagos al equipo
            'cash_shift',            // turnos de caja
            'cash_closing',
            'managerial_accounting',
            'expenses',
            'product_sales',         // venta simple de producto
            'loyalty',               // sellos y recompensas
            'promotions',
            'social_posts',          // calendario y redaccion de publicaciones
            'no_show_penalties',
            'booking_deposit',       // abono para separar la cita
            'permissions_management',
            'audit_logs',
            'reports',
        ];
    }

    /**
     * Como se llama cada modulo en pantalla, y en que grupo va.
     *
     * Las llaves del catalogo son identificadores -- `no_show_penalties`,
     * `managerial_accounting` -- y son las correctas para el codigo. Pero el
     * panel las estaba mostrando crudas, asi que quien configura un negocio
     * tenia que adivinar que enciende. Un interruptor que dice
     * `whatsapp_agent` no se puede prender con confianza.
     *
     * @return array<string, array{label: string, group: string, help: string}>
     */
    public static function labels(): array
    {
        return [
            'multi_location' => ['group' => 'Agenda', 'label' => 'Varias sedes', 'help' => 'Más de un local bajo el mismo negocio, con clientes y catálogo compartidos. Con una sola sede no cambia nada en pantalla.'],
            'scheduling' => ['group' => 'Agenda', 'label' => 'Agenda y citas', 'help' => 'El calendario y el motor de disponibilidad. Sin esto no hay producto.'],
            'online_booking' => ['group' => 'Agenda', 'label' => 'Reserva en línea', 'help' => 'Página pública donde el cliente se agenda solo.'],
            'whatsapp_agent' => ['group' => 'Agenda', 'label' => 'Agente de WhatsApp', 'help' => 'Asistente de IA que agenda por chat.'],
            'reminders' => ['group' => 'Agenda', 'label' => 'Recordatorios', 'help' => 'Avisos automáticos de cita y de retoque.'],
            'multi_resource' => ['group' => 'Agenda', 'label' => 'Servicios con varios recursos', 'help' => 'Un servicio que ocupa a la vez a una persona y una cabina.'],
            'no_show_penalties' => ['group' => 'Agenda', 'label' => 'Registro de inasistencias', 'help' => 'Anota en la ficha cuando alguien no llega.'],
            'booking_deposit' => ['group' => 'Agenda', 'label' => 'Abono para separar', 'help' => 'Pide un adelanto al reservar en línea. Baja las inasistencias, pero es fricción: viene apagado.'],

            'clients' => ['group' => 'Clientes', 'label' => 'Base de clientes', 'help' => 'Fichas con nombre y teléfono.'],
            'client_history' => ['group' => 'Clientes', 'label' => 'Historial y fotos', 'help' => 'Qué se le hizo, cuánto gastó y fotos del trabajo.'],
            'loyalty' => ['group' => 'Clientes', 'label' => 'Fidelización', 'help' => 'Tarjetas de sellos y recompensas.'],
            'promotions' => ['group' => 'Clientes', 'label' => 'Promociones', 'help' => 'Descuentos y cupones.'],
            'social_posts' => ['group' => 'Clientes', 'label' => 'Publicaciones', 'help' => 'Calendario de redes que se llena solo con lo que pasa en la agenda, y redacta el texto. Publicar sigue siendo manual.'],

            'cash_closing' => ['group' => 'Dinero', 'label' => 'Cierre del día', 'help' => 'Cuadrar lo que hay en caja contra lo que se cobró.'],
            'cash_shift' => ['group' => 'Dinero', 'label' => 'Turnos de caja', 'help' => 'Abrir y cerrar caja por persona. En un spa casi nunca se usa: viene apagado.'],
            'expenses' => ['group' => 'Dinero', 'label' => 'Gastos', 'help' => 'Lo que sale, por fecha contable.'],
            'commissions' => ['group' => 'Dinero', 'label' => 'Comisiones', 'help' => 'Porcentaje por persona y por servicio.'],
            'payroll' => ['group' => 'Dinero', 'label' => 'Nómina', 'help' => 'Liquidar y pagarle al equipo, con anticipos y descuentos.'],
            'product_sales' => ['group' => 'Dinero', 'label' => 'Venta de producto', 'help' => 'Vender esmaltes y demás sin agendar una cita.'],
            'managerial_accounting' => ['group' => 'Dinero', 'label' => 'Contabilidad gerencial', 'help' => 'Cerrar periodos contables.'],

            'reports' => ['group' => 'Administración', 'label' => 'Reportes', 'help' => 'Resumen del día y del periodo.'],
            'permissions_management' => ['group' => 'Administración', 'label' => 'Permisos del equipo', 'help' => 'Decidir quién ve qué. Encendido desde el plan básico.'],
            'audit_logs' => ['group' => 'Administración', 'label' => 'Auditoría', 'help' => 'Registro de quién hizo qué.'],
        ];
    }

    /** @return list<string> */
    public static function groups(): array
    {
        return ['Agenda', 'Clientes', 'Dinero', 'Administración'];
    }

    /**
     * El catalogo con su etiqueta, agrupado y en el orden en que se muestra.
     *
     * @return list<array{key: string, label: string, group: string, help: string}>
     */
    public static function describedCatalog(): array
    {
        $labels = self::labels();
        $result = [];

        foreach (self::groups() as $group) {
            foreach (self::catalog() as $key) {
                if (($labels[$key]['group'] ?? null) !== $group) {
                    continue;
                }

                $result[] = ['key' => $key] + $labels[$key];
            }
        }

        // Una bandera nueva sin etiqueta igual aparece, con su llave cruda y
        // al final. Es feo a proposito: se nota y se corrige, en vez de
        // desaparecer del panel sin que nadie lo note.
        foreach (self::catalog() as $key) {
            if (! isset($labels[$key])) {
                $result[] = ['key' => $key, 'label' => $key, 'group' => 'Sin clasificar', 'help' => ''];
            }
        }

        return $result;
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

            // Desde el plan mas basico: decidir quien ve la base de clientes
            // no es una funcion avanzada, es la unica forma que tiene el
            // dueno de proteger lo suyo.
            'permissions_management' => true,
        ]);
    }

    /** @return array<string, bool> */
    public static function pro(): array
    {
        return array_merge(self::basico(), [
            'multi_location' => true,
            'online_booking' => true,
            'client_history' => true,
            'commissions' => true,
            'payroll' => true,
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
     * `social_posts` entra SOLO aca, mismo criterio que `whatsapp_agent`: las
     * dos le pagan tokens al IA Core cada vez que se usan. Un flag que
     * consume plata de la plataforma no se regala con un plan intermedio.
     *
     * Full tampoco enciende `cash_shift` ni `booking_deposit`. Ninguna de las
     * dos es una funcion "avanzada":
     *
     * - `cash_shift` es una forma distinta de operar la caja.
     * - `booking_deposit` le PIDE PLATA POR ADELANTADO al cliente. Baja las
     *   inasistencias, pero tambien espanta a quien solo queria una hora de
     *   manicure. Encenderla porque el negocio contrato el plan mas caro seria
     *   cambiarle la tasa de conversion sin que lo haya pedido.
     *
     * Las dos se encienden negocio por negocio.
     */
    public static function full(): array
    {
        return array_merge(
            array_map(fn () => true, array_flip(self::catalog())),
            ['cash_shift' => false, 'booking_deposit' => false],
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
