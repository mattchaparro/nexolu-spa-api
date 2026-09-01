<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ResourceResource;
use App\Models\Resource;
use App\Support\LocationScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ResourceController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        try {
            $sedes = LocationScope::for($request->user())->filterFor(
                $request->filled('location_id') ? $request->integer('location_id') : null,
            );
        } catch (\DomainException) {
            // Pedir una sede que no le toca devuelve vacio, no un error: un
            // mensaje distinto sirve para tantear que sedes existen.
            return ResourceResource::collection(collect());
        }

        $resources = Resource::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->boolean('only_active', true), fn ($q) => $q->where('is_active', true))
            /*
             * La sede LIMITA, no solo filtra.
             *
             * Con `location_id` se pide una en concreto -- es lo que hace que
             * el modal de agendar no ofrezca a alguien del otro local. Sin el,
             * NO vienen todas: vienen las que esta persona puede ver. Quien
             * administra Cedritos no tiene por que conocer al equipo de
             * Chapinero, y un limite que se salta omitiendo un parametro no es
             * un limite.
             */
            ->when($sedes !== null, fn ($q) => $q->whereIn('location_id', $sedes))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ResourceResource::collection($resources);
    }
}
