<?php

namespace App\Support;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fuente unica de verdad de los permisos.
 *
 * Agregar un permiso es tocar UN array en UN archivo: se crea en la base con
 * `php artisan permissions:sync`, se asigna al rol admin en el mismo comando, y
 * la pantalla de Permisos lo muestra sola.
 *
 * Los nombres viven aca y no como cadenas sueltas en las vistas. Blue Souls
 * comparaba `role == 'admin'` a mano en una docena de componentes: cambiar el
 * nombre de un rol implicaba buscar y rezar.
 */
class PermissionCatalog
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_STAFF = 'staff';

    public const ROLE_RECEPTION = 'reception';

    /**
     * @var array<string, array{label: string, icon: string}>
     */
    private const CATEGORIES = [
        'agenda' => ['label' => 'Agenda y citas', 'icon' => 'event'],
        'clientes' => ['label' => 'Clientes', 'icon' => 'people'],
        'catalogo' => ['label' => 'Servicios y equipo', 'icon' => 'design_services'],
        'caja' => ['label' => 'Caja y cobros', 'icon' => 'point_of_sale'],
        'finanzas' => ['label' => 'Finanzas', 'icon' => 'account_balance_wallet'],
        'reportes' => ['label' => 'Reportes', 'icon' => 'bar_chart'],
        'ia' => ['label' => 'Asistente de IA', 'icon' => 'smart_toy'],
        'administracion' => ['label' => 'Administracion', 'icon' => 'admin_panel_settings'],
    ];

    /**
     * @return list<array{name:string, category:string, label:string, description:string, feature?:string}>
     */
    public static function all(): array
    {
        return [
            // Agenda
            ['name' => 'citas.ver', 'category' => 'agenda', 'label' => 'Ver la agenda', 'description' => 'Ver las citas del negocio.'],
            ['name' => 'citas.ver_todas', 'category' => 'agenda', 'label' => 'Ver la agenda completa', 'description' => 'Ver las citas de todo el equipo, no solo las propias.'],
            ['name' => 'citas.crear', 'category' => 'agenda', 'label' => 'Agendar', 'description' => 'Crear citas nuevas.'],
            ['name' => 'citas.editar', 'category' => 'agenda', 'label' => 'Reagendar', 'description' => 'Mover una cita de hora o de profesional.'],
            ['name' => 'citas.cancelar', 'category' => 'agenda', 'label' => 'Cancelar citas', 'description' => 'Cancelar y marcar inasistencias.'],
            ['name' => 'horarios.gestionar', 'category' => 'agenda', 'label' => 'Gestionar horarios', 'description' => 'Definir turnos, vacaciones y bloqueos.'],

            // Clientes
            ['name' => 'clientes.ver', 'category' => 'clientes', 'label' => 'Ver clientes', 'description' => 'Consultar la base de clientes.'],
            ['name' => 'clientes.gestionar', 'category' => 'clientes', 'label' => 'Gestionar clientes', 'description' => 'Crear y editar clientes.'],
            ['name' => 'clientes.historial', 'category' => 'clientes', 'label' => 'Ver historial', 'description' => 'Consultar el historial de servicios de un cliente.', 'feature' => 'client_history'],

            // Catalogo
            ['name' => 'servicios.gestionar', 'category' => 'catalogo', 'label' => 'Gestionar servicios', 'description' => 'Crear y editar el catalogo, precios y duraciones.'],
            ['name' => 'recursos.gestionar', 'category' => 'catalogo', 'label' => 'Gestionar equipo y recursos', 'description' => 'Administrar profesionales, sillas y cabinas.'],

            // Caja
            ['name' => 'caja.cobrar', 'category' => 'caja', 'label' => 'Cobrar', 'description' => 'Cerrar y cobrar un servicio prestado.', 'feature' => 'cash_shift'],
            ['name' => 'caja.turno', 'category' => 'caja', 'label' => 'Abrir y cerrar turno', 'description' => 'Manejar el turno de caja propio.', 'feature' => 'cash_shift'],
            ['name' => 'caja.cierre', 'category' => 'caja', 'label' => 'Cerrar caja del dia', 'description' => 'Hacer el cierre diario del negocio.', 'feature' => 'cash_closing'],

            // Finanzas
            ['name' => 'gastos.gestionar', 'category' => 'finanzas', 'label' => 'Gestionar gastos', 'description' => 'Registrar y editar gastos.', 'feature' => 'expenses'],
            ['name' => 'comisiones.ver', 'category' => 'finanzas', 'label' => 'Ver comisiones', 'description' => 'Consultar comisiones del equipo.', 'feature' => 'commissions'],
            ['name' => 'contabilidad.ver', 'category' => 'finanzas', 'label' => 'Contabilidad gerencial', 'description' => 'Ver y cerrar periodos contables.', 'feature' => 'managerial_accounting'],

            // Reportes
            ['name' => 'reportes.ver', 'category' => 'reportes', 'label' => 'Ver reportes', 'description' => 'Consultar reportes del negocio.', 'feature' => 'reports'],

            // IA
            ['name' => 'ia.usar', 'category' => 'ia', 'label' => 'Usar el asistente', 'description' => 'Conversar con el asistente de IA.'],

            // Administracion
            ['name' => 'permisos.gestionar', 'category' => 'administracion', 'label' => 'Gestionar permisos', 'description' => 'Definir que puede hacer cada miembro del equipo.', 'feature' => 'permissions_management'],
            ['name' => 'auditoria.ver', 'category' => 'administracion', 'label' => 'Ver auditoria', 'description' => 'Consultar el registro de acciones.', 'feature' => 'audit_logs'],
            ['name' => 'negocio.configurar', 'category' => 'administracion', 'label' => 'Configurar el negocio', 'description' => 'Cambiar datos, horarios y politicas del negocio.'],
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_column(self::all(), 'name');
    }

    /** @return array<string, array{label:string, icon:string}> */
    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    /**
     * Permisos por defecto de cada rol. El admin los tiene todos siempre.
     *
     * @return list<string>
     */
    public static function defaultsForRole(string $role): array
    {
        return match ($role) {
            self::ROLE_ADMIN => self::names(),

            // El profesional ve y atiende lo suyo; no ve la agenda completa ni
            // el dinero del negocio.
            self::ROLE_STAFF => [
                'citas.ver', 'citas.crear', 'citas.editar',
                'clientes.ver', 'clientes.historial',
                'caja.cobrar', 'caja.turno',
                'ia.usar',
            ],

            // Recepcion agenda para todos y cobra, pero no toca finanzas.
            self::ROLE_RECEPTION => [
                'citas.ver', 'citas.ver_todas', 'citas.crear', 'citas.editar', 'citas.cancelar',
                'clientes.ver', 'clientes.gestionar', 'clientes.historial',
                'caja.cobrar', 'caja.turno',
                'ia.usar',
            ],

            default => [],
        };
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::ROLE_ADMIN, self::ROLE_STAFF, self::ROLE_RECEPTION];
    }

    /**
     * Crea permisos y roles faltantes. Idempotente: correrlo dos veces no
     * duplica nada ni revoca lo que un negocio haya ajustado a mano.
     */
    public static function sync(): void
    {
        foreach (self::names() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::roles() as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->givePermissionTo(self::defaultsForRole($roleName));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
