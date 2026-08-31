<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Location;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Quien puede hacer que, persona por persona.
 *
 * El catalogo se expone agrupado y con descripcion porque la decision no es
 * tecnica: quien administra el negocio tiene que entender que esta dando. La
 * mas importante -- acceso a los datos de contacto de sus clientes -- se marca
 * aparte, porque esa base es el activo del negocio.
 *
 * Para el equipo (profesional, recepcion) el rol es una etiqueta y todos los
 * permisos son directos: lo que se ve marcado es exactamente lo que la persona
 * puede hacer. El administrador es la excepcion -- los tiene todos por su rol,
 * y por eso no se edita aca sino cambiandole el rol.
 */
class PermissionController
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        $users = User::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_super_admin', false)
            ->with(['resource', 'locations'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->fullName(),
                'email' => $u->email,
                'is_active' => (bool) $u->is_active,
                'is_self' => $u->id === $request->user()->id,
                'is_admin' => $u->hasRole(PermissionCatalog::ROLE_ADMIN),
                // El dueno. Su vista de sedes no se toca desde aca.
                'is_owner' => (bool) $u->is_owner,
                // Las sedes ASIGNADAS a mano, no las resueltas: la pantalla
                // tiene que poder mostrar "sin asignar" y explicar a donde
                // cae por defecto, en vez de enseñar una marca que nadie puso.
                'location_ids' => $u->locations->pluck('id')->values(),
                'resource_name' => $u->resource?->name,
                'role' => $u->getRoleNames()->first(),
                'permissions' => $u->getAllPermissions()->pluck('name')->values(),
            ])
            ->values();

        return response()->json([
            'users' => $users,
            'catalog' => $this->catalog(),
            'locations' => Location::where('is_active', true)
                ->get()
                ->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name])
                ->values(),
            'roles' => array_map(fn (string $role) => [
                'name' => $role,
                'label' => self::ROLE_LABELS[$role] ?? $role,
                'defaults' => PermissionCatalog::defaultsForRole($role),
            ], PermissionCatalog::roles()),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $business = $request->user()->business;

        abort_if($user->business_id !== $business->id, 404);
        abort_if($user->id === $request->user()->id, 422, 'No puedes cambiar tus propios permisos.');
        abort_if(
            $user->hasRole(PermissionCatalog::ROLE_ADMIN),
            422,
            'Un administrador tiene todos los permisos por su rol. Cámbiale el rol primero.',
        );

        $data = $request->validate([
            'role' => ['nullable', Rule::in([PermissionCatalog::ROLE_STAFF, PermissionCatalog::ROLE_RECEPTION])],
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::in(PermissionCatalog::names())],
        ]);

        if (! empty($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        // Los permisos que llegan son la lista COMPLETA: no hay nada heredado
        // que los complete por detras. Desmarcar `clientes.ver` cierra el
        // acceso de verdad.
        $user->syncPermissions($data['permissions']);

        $user = $user->fresh();

        return response()->json([
            'id' => $user->id,
            'role' => $user->getRoleNames()->first(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]);
    }

    /**
     * Que sedes ve una persona.
     *
     * Endpoint aparte del de permisos, y no por comodidad: `update()` se niega
     * a tocar administradores -- tienen todo por su rol -- pero un
     * administrador de Cedritos que NO deba ver Chapinero es exactamente el
     * caso que las sedes vienen a resolver. Son dos ejes distintos: QUE puede
     * hacer, y DONDE.
     */
    public function updateLocations(Request $request, User $user): JsonResponse
    {
        $business = $request->user()->business;

        abort_if($user->business_id !== $business->id, 404);
        abort_if($user->id === $request->user()->id, 422, 'No puedes cambiar tus propias sedes.');

        // Al dueno no se le restringe. Un negocio sin nadie que vea sus dos
        // locales es un negocio que ya no puede administrarse a si mismo.
        abort_if(
            (bool) $user->is_owner,
            422,
            'El dueño ve todas las sedes del negocio. Eso no se puede restringir.',
        );

        $data = $request->validate([
            'location_ids' => ['present', 'array'],
            'location_ids.*' => [
                'integer',
                // Del propio negocio: sin el `where`, un id ajeno le abriria
                // a alguien el local de otro.
                Rule::exists('locations', 'id')->where('business_id', $business->id),
            ],
        ]);

        $user->locations()->sync($data['location_ids']);

        return response()->json([
            'id' => $user->id,
            'location_ids' => $user->locations()->pluck('locations.id')->values(),
            // Lo que de verdad va a ver, ya resuelto: vacio NO es "todas", es
            // "la sede donde trabaja", y la pantalla tiene que poder decirlo.
            'effective_location_ids' => $user->fresh()->locationScope(),
        ]);
    }

    private const ROLE_LABELS = [
        PermissionCatalog::ROLE_ADMIN => 'Administrador',
        PermissionCatalog::ROLE_STAFF => 'Profesional',
        PermissionCatalog::ROLE_RECEPTION => 'Recepción',
    ];

    /** @return list<array<string, mixed>> */
    private function catalog(): array
    {
        $byCategory = [];

        foreach (PermissionCatalog::all() as $permission) {
            $byCategory[$permission['category']][] = [
                'name' => $permission['name'],
                'label' => $permission['label'],
                'description' => $permission['description'],
                'feature' => $permission['feature'] ?? null,
                // Marcado para que quien decide lo vea distinto: es el permiso
                // que expone los datos de contacto del negocio.
                'sensitive' => in_array($permission['name'], ['clientes.ver', 'clientes.historial'], true),
            ];
        }

        $result = [];

        foreach (PermissionCatalog::categories() as $key => $meta) {
            if (! isset($byCategory[$key])) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'permissions' => $byCategory[$key],
            ];
        }

        return $result;
    }
}
