<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ResourceResource;
use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ResourceController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $resources = Resource::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->boolean('only_active', true), fn ($q) => $q->where('is_active', true))
            /*
             * Solo los de una sede. Es lo que hace que el modal de agendar y
             * el de reagendar no ofrezcan a alguien del otro local: se agenda
             * EN una sede, punto, igual que se cobra en una caja.
             *
             * El scope de negocio ya deja fuera las sedes ajenas, asi que un
             * id prestado devuelve una lista vacia y no la de otro.
             */
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ResourceResource::collection($resources);
    }
}
