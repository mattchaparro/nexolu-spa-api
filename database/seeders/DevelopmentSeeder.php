<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Resource;
use App\Models\ResourceSchedule;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Un negocio de ejemplo con datos suficientes para ejercitar la agenda.
 *
 * Las duraciones de los servicios son deliberadamente dispares (20, 45, 90 y
 * 180 minutos): es justo lo que Blue Souls no podia representar con sus
 * bloques fijos de 120 minutos, y lo que las pruebas tienen que ejercitar.
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        PermissionCatalog::sync();

        $business = Business::create([
            'name' => 'Luxury Nails Spa',
            'slug' => 'luxury-nails',
            'vertical' => BusinessFeaturePresets::VERTICAL_SPA_UNAS,
            'timezone' => 'America/Bogota',
            'country_code' => 'CO',
            'currency' => 'COP',
            'subscription_plan' => BusinessFeaturePresets::PLAN_FULL,
            'feature_flags' => BusinessFeaturePresets::full(),
            'scheduling_settings' => [
                'slot_granularity_min' => 15,
                'min_booking_notice_min' => 60,
                'min_cancellation_notice_min' => 180,
                'max_booking_horizon_days' => 60,
            ],
            'is_active' => true,
        ]);

        $admin = User::create([
            'business_id' => $business->id,
            'name' => 'Admin',
            'last_name' => 'Demo',
            'email' => 'demo@nexolu.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $admin->assignRole(PermissionCatalog::ROLE_ADMIN);

        $category = ServiceCategory::create([
            'business_id' => $business->id,
            'name' => 'Manos y pies',
            'sort_order' => 1,
        ]);

        // Tres profesionales, cada una con su color en la agenda.
        $staff = collect([
            ['Maria', '#4f46e5'],
            ['Ana', '#0f766e'],
            ['Lucia', '#a16207'],
        ])->map(function (array $row, int $i) use ($business) {
            $user = User::create([
                'business_id' => $business->id,
                'name' => $row[0],
                'email' => strtolower($row[0]).'@nexolu.test',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]);
            $user->assignRole(PermissionCatalog::ROLE_STAFF);

            $resource = Resource::create([
                'business_id' => $business->id,
                'type' => Resource::TYPE_STAFF,
                'user_id' => $user->id,
                'name' => $row[0],
                'color' => $row[1],
                'is_bookable_online' => true,
                'is_active' => true,
                'sort_order' => $i,
            ]);

            // Lunes a sabado, 09:00 a 18:00.
            foreach (range(1, 6) as $weekday) {
                ResourceSchedule::create([
                    'business_id' => $business->id,
                    'resource_id' => $resource->id,
                    'weekday' => $weekday,
                    'start_time' => '09:00:00',
                    'end_time' => '18:00:00',
                    'effective_from' => now()->subMonth()->toDateString(),
                ]);
            }

            return $resource;
        });

        // Una cabina: permite ejercitar servicios que ocupan mas de un recurso.
        Resource::create([
            'business_id' => $business->id,
            'type' => Resource::TYPE_ROOM,
            'name' => 'Cabina 1',
            'is_bookable_online' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $services = [
            ['Retoque de esmalte', 20, 0, 5, 25000],
            ['Manicure clasico', 45, 0, 10, 45000],
            ['Manicure semipermanente', 90, 5, 15, 85000],
            ['Uñas acrilicas', 180, 10, 20, 180000],
        ];

        foreach ($services as $i => [$name, $duration, $before, $after, $price]) {
            $service = Service::create([
                'business_id' => $business->id,
                'name' => $name,
                'slug' => str($name)->slug()->value(),
                'service_category_id' => $category->id,
                'duration_min' => $duration,
                'buffer_before_min' => $before,
                'buffer_after_min' => $after,
                'price' => $price,
                'commission_rate' => 0.30,
                'is_bookable_online' => true,
                'is_active' => true,
                'sort_order' => $i,
            ]);

            $service->resources()->attach($staff->pluck('id'));
        }

        $this->command?->info('Negocio "Luxury Nails Spa" creado. Login: demo@nexolu.test / password123');
    }
}
