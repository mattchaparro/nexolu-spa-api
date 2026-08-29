<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // El scope global de BelongsToBusiness ya limita al negocio del
        // usuario autenticado; no hace falta filtrar a mano.
        $services = Service::query()
            ->with(['category', 'resources'])
            ->when($request->boolean('only_active', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ServiceResource::collection($services);
    }
}
