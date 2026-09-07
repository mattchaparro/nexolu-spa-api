<?php

namespace Tests\Feature\Migracion;

use App\Services\Migration\Importadores\ImportaUsuarios;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las cuentas que se crean al migrar.
 *
 * Lo que se defiende acá es un límite de seguridad, no una comodidad: en el
 * sistema viejo hay usuarios con rol `client` — clientas que se crearon un
 * acceso para ver su tarjeta de sellos. Darles entrada al panel del negocio
 * sería la peor fuga posible, y es un error de una sola línea.
 *
 * Y la regla de producto que hace falta para arrancar: una persona puede ser
 * dueña Y atender. En el sistema viejo eso son dos cuentas porque allá un
 * usuario tiene un rol y ya. Acá es una sola.
 */
class ImportaUsuariosTest extends TestCase
{
    use RefreshDatabase;

    private function reglas(): array
    {
        $r = new \ReflectionClass(ImportaUsuarios::class);

        return [
            'roles' => $r->getConstant('ROLES'),
            'jerarquia' => $r->getConstant('JERARQUIA'),
        ];
    }

    public function test_el_rol_client_del_sistema_viejo_no_da_entrada_al_panel(): void
    {
        /*
         * Es EL límite. `client` no está en el mapa de roles, y la consulta
         * filtra por las llaves de ese mapa: una clienta no puede terminar con
         * acceso al panel ni por descuido.
         */
        $roles = $this->reglas()['roles'];

        $this->assertArrayNotHasKey('client', $roles);
        $this->assertSame(['admin', 'employee'], array_keys($roles));
    }

    public function test_una_manicurista_llega_como_staff_y_no_como_admin(): void
    {
        // El rol que el dueño pidió: ve su propia agenda, cobra, y no ve la
        // lista de clientas ni la agenda de las demás.
        $roles = $this->reglas()['roles'];

        $this->assertSame(PermissionCatalog::ROLE_STAFF, $roles['employee']);
        $this->assertSame(PermissionCatalog::ROLE_ADMIN, $roles['admin']);
    }

    public function test_al_fusionar_dos_cuentas_gana_el_rol_mas_alto(): void
    {
        /*
         * Alejandra es dueña del local y además atiende. En el sistema viejo
         * eso son dos cuentas; acá es una sola, con el rol más alto y su ficha
         * ligada. Si ganara el rol más bajo, la dueña entraría a su propio
         * negocio sin poder ver sus reportes.
         */
        $r = new \ReflectionClass(ImportaUsuarios::class);
        $importador = $r->newInstanceWithoutConstructor();

        $metodo = $r->getMethod('rolMasAlto');
        $metodo->setAccessible(true);

        $admin = PermissionCatalog::ROLE_ADMIN;
        $staff = PermissionCatalog::ROLE_STAFF;

        $this->assertSame($admin, $metodo->invoke($importador, $staff, $admin));
        $this->assertSame($admin, $metodo->invoke($importador, $admin, $staff));
        $this->assertSame($staff, $metodo->invoke($importador, $staff, $staff));
    }

    public function test_el_rol_staff_no_puede_ver_la_lista_de_clientas(): void
    {
        /*
         * La restricción que el dueño pidió explícitamente: "no quiero que mi
         * empleado pueda agendar ni mucho menos ver los datos de mis
         * clientes". Si alguien agrega `clientes.ver` al rol staff, esto lo
         * detiene.
         */
        $staff = PermissionCatalog::defaultsForRole(PermissionCatalog::ROLE_STAFF);

        $this->assertNotContains('clientes.ver', $staff);
        $this->assertNotContains('clientes.historial', $staff);
        $this->assertNotContains('citas.crear', $staff);
        $this->assertNotContains('citas.ver_todas', $staff);
    }
}
