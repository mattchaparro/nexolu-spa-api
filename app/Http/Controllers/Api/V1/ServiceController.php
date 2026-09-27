<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\LoQueMasPiden;
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

        /*
         * Cuántas veces se ha pedido cada uno en el último año: los
         * selectores de servicio (agendar, sin cita, corregir) ponen primero
         * los más pedidos. Es la misma cuenta que usa el bot para su lista.
         */
        $veces = LoQueMasPiden::cuantasVeces((int) $request->user()->business_id);
        $services->each(fn (Service $s) => $s->setAttribute('times_requested', $veces[$s->id] ?? 0));

        return ServiceResource::collection($services);
    }
}
