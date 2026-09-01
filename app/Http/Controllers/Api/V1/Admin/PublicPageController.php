<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Location;
use App\Models\Service;
use App\Support\ImageStorage;
use App\Support\PublicProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La pagina publica, desde el lado del negocio.
 *
 * Lo que se edita aca es poco a proposito: una frase, un parrafo, como
 * escribirle, y la portada. El constructor de landings viene aparte y esto
 * queda como el modo simple -- que ademas es el que va a usar el spa que abre
 * el lunes y no quiere armar nada.
 */
class PublicPageController
{
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        return response()->json([
            'enabled' => $business->hasFeature('online_booking'),
            'slug' => $business->slug,
            'profile' => array_merge(
                array_fill_keys(PublicProfile::fields(), null),
                $business->public_profile ?? [],
            ),
            'labels' => PublicProfile::labels(),
            'logo_url' => ImageStorage::url($business->logo_path),
            'cover_url' => ImageStorage::url($business->cover_path),

            /*
             * Las sedes, para que la pantalla arme el enlace de cada una.
             *
             * Sin esto el enlace por sede existe y nadie lo encuentra: quien
             * administra el negocio no tiene por que deducir que a la URL se
             * le pega el slug del local. Van solo cuando hay mas de una -- con
             * un solo local, el enlace del negocio ya es el de esa sede.
             */
            'locations' => $business->locations()->where('is_active', true)->count() > 1
                // La principal primero, igual que en la pagina publica: es la
                // que quien administra espera ver arriba.
                ? $business->locations()->where('is_active', true)
                    ->reorder()->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('name')
                    ->get()
                    ->map(fn (Location $l) => [
                        'id' => $l->id,
                        'slug' => $l->slug,
                        'name' => $l->name,
                        'city' => $l->city,
                    ])->values()
                : [],

            // Que se ofrece por internet y que no. Es la decision que mas se
            // olvida: un servicio nuevo entra al catalogo y nadie se acuerda
            // de que la pagina publica no lo muestra.
            'services' => Service::where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')
                ->get()
                ->map(fn (Service $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'is_bookable_online' => (bool) $s->is_bookable_online,
                ])->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        $data = $request->validate([
            'headline' => ['nullable', 'string', 'max:120'],
            'about' => ['nullable', 'string', 'max:1000'],
            'instagram' => ['nullable', 'string', 'max:120'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'maps_url' => ['nullable', 'string', 'max:500'],
            'google_review_url' => ['nullable', 'string', 'max:500'],
            'show_staff_ratings' => ['nullable', 'boolean'],
            'cover' => ImageStorage::rules(),
        ]);

        if ($request->hasFile('cover')) {
            ImageStorage::delete($business->cover_path);
            $business->cover_path = ImageStorage::store($request->file('cover'), $business->id, 'portada');
        }

        $business->public_profile = PublicProfile::sanitize($data);
        $business->save();

        return $this->show($request);
    }

    /** Que servicios se ofrecen por internet. */
    public function syncServices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_ids' => ['present', 'array'],
            'service_ids.*' => ['integer'],
        ]);

        // Con el scope del negocio puesto: mandar ids de otro spa no puede
        // encender nada suyo.
        Service::whereIn('id', $data['service_ids'])->update(['is_bookable_online' => true]);
        Service::whereNotIn('id', $data['service_ids'])->update(['is_bookable_online' => false]);

        return $this->show($request);
    }
}
