<?php

namespace App\Services\Migration\Importadores;

use App\Models\Resource;
use App\Models\User;
use App\Support\PermissionCatalog;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Las cuentas para entrar al panel.
 *
 * Corre DESPUES del equipo, porque cada cuenta se liga a la ficha de esa
 * persona: es ese vinculo el que hace que "Mi dia" sepa cuales son sus citas.
 *
 * UNA PERSONA PUEDE SER LAS DOS COSAS. En el sistema viejo Alejandra tiene
 * DOS cuentas -- una de administradora y otra de manicurista -- porque alla
 * un usuario tiene un rol y ya. Pero es una sola persona: es duena del local
 * y ademas atiende. Aca eso no necesita dos cuentas: se le da el rol de
 * administradora y se le liga su ficha, y con eso ve el negocio entero Y
 * tiene su propia agenda del dia. Va a pasar en muchos negocios chicos.
 *
 * TRES REGLAS:
 *
 * 1. LA CLAVE VIAJA TAL CUAL. Los dos sistemas son Laravel y guardan bcrypt,
 *    asi que el hash se copia y cada quien entra con la contrasena que ya
 *    usa. Nadie tiene que aprenderse una nueva el dia de la mudanza, que es
 *    justo el dia en que menos paciencia hay.
 *
 * 2. SOLO ENTRA QUIEN HOY PUEDE ENTRAR. Las trece manicuristas inactivas ya
 *    tienen su ficha y su historial atribuido; una cuenta que no van a usar
 *    es una puerta mas que cuidar. Si el negocio reactiva a alguien alla, la
 *    corrida siguiente le crea la cuenta.
 *
 * 3. EL ROL `client` DEL SISTEMA VIEJO NO ES UNA CUENTA DE EQUIPO. Son
 *    clientas que se crearon un acceso para ver su tarjeta de sellos. Darles
 *    entrada al panel del negocio seria la peor fuga posible.
 */
class ImportaUsuarios extends Importador
{
    /** @var array<string, string> rol del sistema viejo => rol de aca */
    private const ROLES = [
        'admin' => PermissionCatalog::ROLE_ADMIN,
        'employee' => PermissionCatalog::ROLE_STAFF,
    ];

    /** De mas a menos: cuando se fusionan dos cuentas, gana la primera. */
    private const JERARQUIA = [PermissionCatalog::ROLE_ADMIN, PermissionCatalog::ROLE_STAFF];

    public function nombre(): string
    {
        return 'Usuarios';
    }

    public function correr(): void
    {
        /*
         * Sin los roles sincronizados esto muere con un error de la libreria
         * de permisos -- "There is no role named `admin`" -- que no le dice a
         * nadie que lo que falta es un comando. El deploy ya corre
         * `permissions:sync`, pero una base recien creada a mano no.
         */
        if (! Role::query()->where('name', PermissionCatalog::ROLE_ADMIN)->exists()) {
            $this->reporte->aviso(
                'Usuarios',
                'Faltan los roles: corre `php artisan permissions:sync` y vuelve a intentar. '
                .'No se creo ninguna cuenta.',
            );

            return;
        }

        $personas = $this->personasDelLegacy();

        foreach ($personas as $persona) {
            $this->una($persona);
        }
    }

    /**
     * Las cuentas del sistema viejo, ya fusionadas por persona.
     *
     * @return list<array{legacy_id:int, base:object, rol:string, fusionadas:list<int>}>
     */
    private function personasDelLegacy(): array
    {
        $filas = $this->legacy('users')
            ->leftJoin('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->leftJoin('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereNull('users.deleted_at')
            ->where('users.is_active', true)
            ->whereIn('roles.name', array_keys(self::ROLES))
            ->orderBy('users.id')
            ->get([
                'users.id as id',
                'users.name',
                'users.last_name',
                'users.email',
                'users.cellphone',
                'users.password',
                'roles.name as rol',
            ]);

        $porPersona = [];

        foreach ($filas as $fila) {
            if (trim((string) $fila->password) === '') {
                $this->reporte->aviso(
                    'Usuarios',
                    "{$fila->name} no tiene contrasena en el sistema viejo: no se le creo cuenta.",
                );

                continue;
            }

            // Las cuentas de una misma persona se juntan bajo la ficha buena.
            $clave = ImportaEquipo::MISMA_PERSONA[(int) $fila->id] ?? (int) $fila->id;
            $rol = self::ROLES[$fila->rol];

            if (! isset($porPersona[$clave])) {
                $porPersona[$clave] = [
                    'legacy_id' => $clave,
                    'base' => $fila,
                    'rol' => $rol,
                    'fusionadas' => [(int) $fila->id],
                ];

                continue;
            }

            $porPersona[$clave]['fusionadas'][] = (int) $fila->id;
            $porPersona[$clave]['rol'] = $this->rolMasAlto($porPersona[$clave]['rol'], $rol);

            /*
             * La cuenta BASE es la de la ficha que atiende, no la
             * administrativa: su correo y su clave son los que esa persona usa
             * todos los dias. La cuenta generica de administracion suele ser
             * la que nadie recuerda.
             */
            if ((int) $fila->id === $clave) {
                $porPersona[$clave]['base'] = $fila;
            }
        }

        return array_values($porPersona);
    }

    private function rolMasAlto(string $a, string $b): string
    {
        foreach (self::JERARQUIA as $rol) {
            if ($a === $rol || $b === $rol) {
                return $rol;
            }
        }

        return $a;
    }

    /** @param array{legacy_id:int, base:object, rol:string, fusionadas:list<int>} $persona */
    private function una(array $persona): void
    {
        $legacyId = $persona['legacy_id'];
        $base = $persona['base'];

        if ($this->map->yaExiste('user', $legacyId)) {
            $this->reporte->saltado('Usuarios');

            return;
        }

        if ($this->simular) {
            $this->reporte->creado('Usuarios');
            $this->avisarFusion($persona);

            return;
        }

        /*
         * Si ya hay alguien con ese correo, se liga en vez de crear otro.
         * Dos cuentas con el mismo correo es una pantalla de login que no
         * sabe a cual entrar.
         */
        $existente = User::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->where('email', $base->email)
            ->first();

        /*
         * Ese correo puede estar tomado por OTRO negocio: el de verdad, si
         * este es un negocio de practica hecho con los mismos datos. El correo
         * es unico en toda la plataforma, asi que aca no se puede crear.
         *
         * Se avisa y se sigue, en vez de tumbar la importacion: todo lo demas
         * -- clientas, historial, agenda -- si se puede traer, y las cuentas
         * de un negocio de practica se crean a mano con otro correo.
         */
        if ($existente === null && User::withoutGlobalScope('business')
            ->where('email', $base->email)->exists()) {
            $this->reporte->aviso(
                'Usuarios',
                "El correo {$base->email} ya es de otro negocio: no se creo cuenta aca.",
            );

            return;
        }

        $usuario = $existente ?? DB::transaction(function () use ($base, $persona) {
            $u = User::create([
                'business_id' => $this->business->id,
                'name' => trim((string) $base->name),
                'last_name' => trim((string) ($base->last_name ?? '')) ?: null,
                'email' => $base->email,
                'phone' => trim((string) ($base->cellphone ?? '')) ?: null,
                // El hash tal cual: los dos sistemas son Laravel con bcrypt.
                'password' => $base->password,
                'is_active' => true,
                // Quien administra el local es dueno: ve todas las sedes y eso
                // no se le puede restringir.
                'is_owner' => $persona['rol'] === PermissionCatalog::ROLE_ADMIN,
            ]);

            PermissionCatalog::applyRole($u, $persona['rol']);

            return $u;
        });

        /*
         * El vinculo cuenta <-> ficha. Es lo que hace que "Mi dia" sepa
         * cuales son SUS citas, y lo que permite que una duena que ademas
         * atiende tenga las dos vistas con una sola cuenta.
         */
        $recurso = $this->map->idNuevo('resource', $legacyId);

        if ($recurso !== null) {
            Resource::withoutGlobalScope('business')
                ->where('id', $recurso)
                ->whereNull('user_id')
                ->update(['user_id' => $usuario->id]);
        }

        $this->map->anotar('user', $legacyId, $usuario->id);
        $this->reporte->creado('Usuarios');
        $this->avisarFusion($persona);
    }

    /** @param array{base:object, rol:string, fusionadas:list<int>} $persona */
    private function avisarFusion(array $persona): void
    {
        if (count($persona['fusionadas']) < 2) {
            return;
        }

        $rol = $persona['rol'] === PermissionCatalog::ROLE_ADMIN ? 'administradora' : 'del equipo';

        $this->reporte->aviso(
            'Usuarios',
            "{$persona['base']->name} tenia ".count($persona['fusionadas']).' cuentas en el sistema '
            ."viejo: quedo una sola, {$rol}, y entra con {$persona['base']->email}. Ve el negocio "
            .'completo y ademas su propia agenda del dia.',
        );
    }
}
