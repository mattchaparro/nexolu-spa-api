<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Administracion de todos los negocios de la plataforma.
 *
 * El superadmin no tiene `business_id`, asi que el scope de
 * BelongsToBusiness no lo filtra y estas consultas cruzan todos los tenants
 * a proposito. Es exactamente lo que hace falta aca y exactamente lo que no
 * debe pasar en ningun otro sitio: por eso el panel vive detras de su propio
 * middleware y en su propio archivo de rutas.
 */
class BusinessesController
{
    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $businesses = Business::query()
            ->when($term !== '', fn ($q) => $q->where(function ($sub) use ($term) {
                $sub->where('name', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%");
            }))
            ->when($request->filled('vertical'), fn ($q) => $q->where('vertical', $request->string('vertical')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->get();

        return response()->json($businesses->map(fn (Business $b) => $this->summary($b)));
    }

    public function show(Business $business): JsonResponse
    {
        return response()->json($this->detail($business));
    }

    /**
     * Crea un negocio con su dueno, sus metodos de pago y sus permisos.
     *
     * Todo en una transaccion: un negocio a medias -- sin dueno que pueda
     * entrar, o sin metodo de pago con el que cobrar -- no sirve para nada y
     * hay que limpiarlo a mano.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'vertical' => ['required', Rule::in(BusinessFeaturePresets::verticals())],
            'timezone' => ['nullable', 'string', 'max:64'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'subscription_plan' => ['nullable', Rule::in([
                BusinessFeaturePresets::PLAN_BASICO,
                BusinessFeaturePresets::PLAN_PRO,
                BusinessFeaturePresets::PLAN_FULL,
            ])],
            'phone' => ['nullable', 'string', 'max:32'],

            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'owner_password' => ['required', 'string', 'min:8'],
        ]);

        $business = DB::transaction(function () use ($data) {
            $plan = $data['subscription_plan'] ?? BusinessFeaturePresets::PLAN_PRO;
            $country = strtoupper($data['country_code'] ?? 'CO');

            $business = Business::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'vertical' => $data['vertical'],
                'timezone' => $data['timezone'] ?? config('spa.defaults.timezone'),
                'country_code' => $country,
                'currency' => strtoupper($data['currency'] ?? 'COP'),
                'phone' => isset($data['phone']) ? ChannelPhone::normalize($data['phone'], $country) : null,
                'subscription_plan' => $plan,
                'feature_flags' => array_merge(
                    BusinessFeaturePresets::fromPlan($plan),
                    BusinessFeaturePresets::fromVertical($data['vertical']),
                ),
                'scheduling_settings' => config('spa.defaults'),
                'is_active' => true,
            ]);

            PermissionCatalog::sync();

            $owner = User::create([
                'business_id' => $business->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => Hash::make($data['owner_password']),
                'is_active' => true,
            ]);
            PermissionCatalog::applyRole($owner, PermissionCatalog::ROLE_ADMIN);

            // Efectivo siempre: sin al menos un metodo de pago no se puede
            // cobrar, y ningun negocio arranca sin cobrar en efectivo.
            PaymentMethod::create([
                'business_id' => $business->id,
                'name' => 'Efectivo',
                'counts_as_cash' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]);

            return $business;
        });

        return response()->json($this->detail($business), 201);
    }

    public function update(Request $request, Business $business): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'phone' => ['nullable', 'string', 'max:32'],
            'subscription_plan' => ['sometimes', Rule::in([
                BusinessFeaturePresets::PLAN_BASICO,
                BusinessFeaturePresets::PLAN_PRO,
                BusinessFeaturePresets::PLAN_FULL,
            ])],
            'feature_flags' => ['sometimes', 'array'],
            'feature_flags.*' => ['boolean'],
            'scheduling_settings' => ['sometimes', 'array'],
            'scheduling_settings.slot_granularity_min' => ['nullable', 'integer', 'min:5', 'max:60'],
            'scheduling_settings.min_booking_notice_min' => ['nullable', 'integer', 'min:0'],
            'scheduling_settings.min_cancellation_notice_min' => ['nullable', 'integer', 'min:0'],
            'scheduling_settings.max_booking_horizon_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'scheduling_settings.no_show_penalty_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (isset($data['feature_flags'])) {
            // Solo banderas del catalogo: un flag inventado quedaria guardado
            // para siempre sin que nada lo lea.
            $data['feature_flags'] = array_intersect_key(
                $data['feature_flags'],
                array_flip(BusinessFeaturePresets::catalog()),
            );
        }

        if (array_key_exists('phone', $data) && $data['phone'] !== null) {
            $data['phone'] = ChannelPhone::normalize($data['phone'], $business->country_code);
        }

        $business->update($data);

        return response()->json($this->detail($business->fresh()));
    }

    /**
     * Suspende o reactiva un negocio.
     *
     * Suspender NO borra nada ni cancela sus citas: solo impide entrar. Un
     * negocio que se atrasa en el pago y despues se pone al dia tiene que
     * encontrar su agenda intacta.
     */
    public function toggle(Business $business): JsonResponse
    {
        $business->update(['is_active' => ! $business->is_active]);

        return response()->json($this->detail($business->fresh()));
    }

    /** Catalogo de banderas y planes, para que el panel no las tenga duplicadas. */
    public function featureCatalog(): JsonResponse
    {
        return response()->json([
            'flags' => BusinessFeaturePresets::catalog(),
            'plans' => [
                BusinessFeaturePresets::PLAN_BASICO => BusinessFeaturePresets::basico(),
                BusinessFeaturePresets::PLAN_PRO => BusinessFeaturePresets::pro(),
                BusinessFeaturePresets::PLAN_FULL => BusinessFeaturePresets::full(),
            ],
            'verticals' => BusinessFeaturePresets::verticals(),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(Business $business): array
    {
        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'vertical' => $business->vertical,
            'timezone' => $business->businessTimezone(),
            'currency' => $business->currency,
            'subscription_plan' => $business->subscription_plan,
            'is_active' => (bool) $business->is_active,
            'created_at' => $business->created_at?->toDateString(),
            'counts' => $this->counts($business),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Business $business): array
    {
        return $this->summary($business) + [
            'phone' => $business->phone,
            'email' => $business->email,
            'country_code' => $business->country_code,
            'feature_flags' => $business->feature_flags ?? [],
            'resolved_features' => $business->resolvedFeatureFlags(),
            'scheduling_settings' => $business->scheduling_settings ?? config('spa.defaults'),
            'owners' => User::withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->get()
                ->filter(fn (User $u) => $u->hasRole(PermissionCatalog::ROLE_ADMIN))
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->fullName(),
                    'email' => $u->email,
                    'is_active' => (bool) $u->is_active,
                ])->values(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function counts(Business $business): array
    {
        // withoutGlobalScope explicito: el superadmin no tiene business_id, asi
        // que hoy el scope no aplica -- pero si algun dia se llama esto desde
        // un contexto que si lo tiene, los numeros saldrian en cero sin ningun
        // error visible.
        $scope = fn (string $model) => $model::withoutGlobalScope('business')
            ->where('business_id', $business->id);

        return [
            'users' => $scope(User::class)->count(),
            'resources' => $scope(Resource::class)->where('is_active', true)->count(),
            'services' => $scope(Service::class)->where('is_active', true)->count(),
            'appointments_30d' => $scope(Appointment::class)
                ->where('starts_at', '>=', now()->subDays(30))
                ->count(),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'negocio';
        $slug = $base;
        $i = 2;

        while (Business::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
