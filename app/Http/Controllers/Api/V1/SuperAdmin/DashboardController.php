<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController
{
    /**
     * Salud de la plataforma de un vistazo.
     *
     * Todas las consultas cruzan tenants a proposito y llevan
     * withoutGlobalScope explicito: hoy el superadmin no tiene business_id y
     * el scope no aplica, pero dejarlo implicito significa que el dia que
     * alguien llame esto con sesion de negocio los numeros salen en cero sin
     * ningun error.
     */
    public function index(): JsonResponse
    {
        $businesses = Business::query()->get();

        $appointments = Appointment::withoutGlobalScope('business');

        return response()->json([
            'businesses' => [
                'total' => $businesses->count(),
                'active' => $businesses->where('is_active', true)->count(),
                'by_vertical' => $businesses->groupBy('vertical')->map->count(),
                'by_plan' => $businesses->groupBy(fn (Business $b) => $b->subscription_plan ?? 'sin_plan')->map->count(),
            ],
            'users' => User::withoutGlobalScope('business')->where('is_active', true)->count(),
            'appointments' => [
                'last_30d' => (clone $appointments)->where('starts_at', '>=', now()->subDays(30))->count(),
                'upcoming' => (clone $appointments)
                    ->whereIn('status', Appointment::activeStatuses())
                    ->where('starts_at', '>=', now())
                    ->count(),
            ],
            // Negocios sin actividad reciente: lo primero que hay que mirar
            // cuando alguien deja de usar el producto y todavia no lo dice.
            'idle' => $businesses
                ->filter(function (Business $b) {
                    return Appointment::withoutGlobalScope('business')
                        ->where('business_id', $b->id)
                        ->where('starts_at', '>=', now()->subDays(14))
                        ->doesntExist();
                })
                ->map(fn (Business $b) => ['id' => $b->id, 'name' => $b->name])
                ->values(),
        ]);
    }
}
