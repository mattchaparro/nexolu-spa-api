<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Resources\ResourceResource;
use App\Models\Resource;
use App\Models\ResourceSchedule;
use App\Models\User;
use App\Support\ChannelPhone;
use App\Support\ImageStorage;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ResourceAdminController
{
    /**
     * Crea un recurso y, si es una persona con correo, su usuario para entrar.
     *
     * Van juntos a proposito. Crear la profesional en un lado y su cuenta en
     * otro es como se terminan teniendo manicuristas en la agenda que no
     * pueden entrar, y usuarios sin agenda. Una silla o una cabina no llevan
     * usuario: solo se ocupan.
     */
    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $data = $this->validated($request, $business->id);

        $resource = DB::transaction(function () use ($business, $data, $request) {
            $userId = null;

            if (! empty($data['email'])) {
                $user = User::create([
                    'business_id' => $business->id,
                    'name' => $data['name'],
                    'last_name' => $data['last_name'] ?? null,
                    'email' => $data['email'],
                    'phone' => isset($data['phone'])
                        ? ChannelPhone::normalize($data['phone'], $business->country_code)
                        : null,
                    'password' => Hash::make($data['password']),
                    'is_active' => true,
                ]);
                PermissionCatalog::applyRole($user, $data['role'] ?? PermissionCatalog::ROLE_STAFF);
                $userId = $user->id;
            }

            $resource = Resource::create([
                'business_id' => $business->id,
                'type' => $data['type'],
                'user_id' => $userId,
                'name' => trim($data['name'].' '.($data['last_name'] ?? '')),
                'color' => $data['color'] ?? null,
                'is_bookable_online' => $data['is_bookable_online'] ?? true,
                'is_active' => true,
                'sort_order' => $data['sort_order'] ?? 0,
                // Nulo = sin porcentaje propio; cada servicio decide. Distinto
                // de 0, que es "esta persona no gana comision".
                'commission_rate' => $data['commission_rate'] ?? null,
            ]);

            if ($request->hasFile('photo')) {
                $resource->update([
                    'photo_path' => ImageStorage::store($request->file('photo'), $business->id, 'equipo'),
                ]);
            }

            return $resource;
        });

        return response()->json(new ResourceResource($resource), 201);
    }

    public function update(Request $request, Resource $resource): JsonResponse
    {
        $business = $request->user()->business;

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:7'],
            'is_bookable_online' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'photo' => ImageStorage::rules(),
            // `present` para poder BORRARLO: sin eso, `null` se veria igual
            // que "no lo mandaste" y no habria forma de quitarle el porcentaje
            // a alguien una vez puesto.
            'commission_rate' => ['sometimes', 'present', 'nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $resource->update(collect($data)->except('photo')->all());

        if ($request->hasFile('photo')) {
            $anterior = $resource->photo_path;
            $resource->update([
                'photo_path' => ImageStorage::store($request->file('photo'), $business->id, 'equipo'),
            ]);
            ImageStorage::delete($anterior);
        }

        // Desactivar el recurso desactiva tambien su usuario: una profesional
        // que ya no trabaja aca no deberia poder seguir entrando.
        if (array_key_exists('is_active', $data) && $resource->user) {
            $resource->user->update(['is_active' => (bool) $data['is_active']]);
        }

        return response()->json(new ResourceResource($resource->fresh()));
    }

    /** @return JsonResponse Horarios recurrentes del recurso. */
    public function schedules(Resource $resource): JsonResponse
    {
        return response()->json(
            $resource->schedules()->orderBy('weekday')->orderBy('start_time')->get()
                ->map(fn (ResourceSchedule $s) => [
                    'id' => $s->id,
                    'weekday' => $s->weekday,
                    'start_time' => substr((string) $s->start_time, 0, 5),
                    'end_time' => substr((string) $s->end_time, 0, 5),
                    'effective_from' => $s->effective_from?->toDateString(),
                    'effective_to' => $s->effective_to?->toDateString(),
                ])
        );
    }

    /**
     * Reemplaza el horario semanal completo.
     *
     * Se reemplaza en vez de editarse fila por fila porque asi es como se
     * piensa: "esta es mi semana". El formulario manda los siete dias y el
     * backend deja la base igual a eso.
     */
    public function saveSchedules(Request $request, Resource $resource): JsonResponse
    {
        $data = $request->validate([
            'schedules' => ['present', 'array'],
            'schedules.*.weekday' => ['required', 'integer', 'between:1,7'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i', 'after:schedules.*.start_time'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $desde = $data['effective_from'] ?? now()->toDateString();

        DB::transaction(function () use ($resource, $data, $desde) {
            $resource->schedules()->delete();

            foreach ($data['schedules'] as $row) {
                ResourceSchedule::create([
                    'business_id' => $resource->business_id,
                    'resource_id' => $resource->id,
                    'weekday' => $row['weekday'],
                    'start_time' => $row['start_time'].':00',
                    'end_time' => $row['end_time'].':00',
                    'effective_from' => $desde,
                ]);
            }
        });

        return $this->schedules($resource->fresh());
    }

    private function validated(Request $request, int $businessId): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(Resource::types())],
            'name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:7'],
            'is_bookable_online' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'photo' => ImageStorage::rules(),

            // Su porcentaje general. Manda sobre el del servicio: es parte de
            // su acuerdo, y un servicio nuevo no puede cambiarselo en silencio.
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],

            // Solo para recursos que son personas.
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => ['required_with:email', 'nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['nullable', Rule::in(PermissionCatalog::roles())],
        ]);
    }
}
