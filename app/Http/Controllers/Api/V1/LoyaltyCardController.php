<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\JsonResponse;

/**
 * Como va la tarjeta de sellos de un cliente.
 *
 * El saldo se CUENTA en cada consulta, no se lee de un contador guardado: no
 * hay nada que se pueda desincronizar de las visitas reales.
 */
class LoyaltyCardController
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    public function show(Client $client): JsonResponse
    {
        return response()->json($this->loyalty->cardFor($client) ?? ['program' => null]);
    }
}
