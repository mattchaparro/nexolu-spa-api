<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiArgumentException;
use App\Ai\AiCaller;
use App\Ai\Registry;
use App\Models\Business;
use App\Models\Client;
use App\Models\User;
use App\Support\ChannelPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Unico punto por el que el Nexolu IA Core ejecuta algo de este negocio.
 * Contrato fijo (ver docs/openapi/app-contract.json del Core): no se agregan
 * rutas por herramienta.
 *
 * La regla que ordena todo este archivo: EL BLOQUE `context` ES UNA
 * AFIRMACION, NO UNA CREDENCIAL. Viene autenticado con la API key del Core,
 * lo que prueba que la llamada viene del Core -- no que lo que dice sea
 * cierto. Todo se vuelve a resolver contra la base de datos.
 *
 * Y con numero de WhatsApp compartido entre negocios eso deja de ser
 * higiene y pasa a ser la defensa principal: el `business_id` sale de la
 * conversacion que resolvio el puente, y lo que el modelo haya leido en el
 * mensaje de una clienta nunca puede cambiarlo.
 */
class AiToolInvokeController
{
    public function __construct(private readonly Registry $registry) {}

    public function invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tool' => ['required', 'string', 'max:64'],
            'arguments' => ['sometimes', 'array'],
            'context' => ['required', 'array'],
            'context.business_id' => ['required'],
            'context.user_id' => ['required'],
            'context.channel' => ['nullable', 'string', 'max:32'],
        ]);

        $business = Business::withoutGlobalScopes()->find($validated['context']['business_id']);

        if ($business === null || ! $business->is_active) {
            return response()->json(['error' => 'Contexto inválido: el negocio no existe o está inactivo.'], 422);
        }

        $canal = $validated['context']['channel'] ?? 'web';
        $caller = $this->resolveCaller($business, (string) $validated['context']['user_id'], $canal);

        if ($caller === null) {
            return response()->json(['error' => 'Contexto inválido: no se pudo identificar a quien pregunta.'], 422);
        }

        $capability = $this->registry->resolve($validated['tool']);

        if ($capability === null) {
            return response()->json(['error' => "Herramienta '{$validated['tool']}' no reconocida."], 404);
        }

        /*
         * La puerta de las clientas. Una capacidad nueva nace cerrada al
         * publico: si alguien agrega "listar clientes" y olvida pensarlo,
         * lo que pasa es un 403, no una fuga.
         */
        if ($caller->isCustomer() && ! $capability->allowsCustomers()) {
            return response()->json(['error' => 'Esa acción no está disponible por este canal.'], 403);
        }

        if ($capability->requiredFeature() && ! $business->hasFeature($capability->requiredFeature())) {
            return response()->json(['error' => 'Este negocio no tiene habilitada esa función.'], 403);
        }

        // Los permisos son cosa de empleadas. Una clienta no tiene ninguno, y
        // su limite es `allowsCustomers()` mas lo que cada capacidad filtre
        // como "suyo".
        if ($caller->isStaff()
            && $capability->requiredPermission()
            && ! $caller->user->can($capability->requiredPermission())) {
            return response()->json(['error' => 'No tienes permiso para esta acción.'], 403);
        }

        $validator = Validator::make($validated['arguments'] ?? [], $capability->rules());

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        if ($caller->isStaff()) {
            // El resto del código (scopes, BelongsToBusiness) espera un
            // usuario autenticado, igual que en una petición normal.
            Auth::setUser($caller->user);
        }

        try {
            $data = $capability->execute($caller, $validator->validated());
        } catch (AiArgumentException $e) {
            // Un "no pude resolverlo, pregúntale esto": 422 con el texto
            // escrito para que el agente lo use, no un error de sistema.
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $data]);
    }

    /** Lo que el Core cachea para no coordinar despliegues por un permiso. */
    public function catalog(): JsonResponse
    {
        return response()->json(['tools' => $this->registry->catalog()]);
    }

    /**
     * Quien pregunta, resuelto de verdad.
     *
     * El canal decide como se lee `user_id`: por WhatsApp es un TELEFONO y
     * quien escribe es una clienta; por el panel es el id de una empleada.
     * Tomarlo al reves seria darle a una clienta los permisos de un usuario
     * cuyo id coincida con su numero.
     */
    private function resolveCaller(Business $business, string $userId, string $channel): ?AiCaller
    {
        if ($channel === 'whatsapp') {
            $phone = ChannelPhone::normalize($userId, $business->country_code);

            if ($phone === null) {
                return null;
            }

            $client = Client::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('phone', $phone)
                ->first();

            return AiCaller::customer($business, $phone, $client, $channel);
        }

        $user = User::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->find($userId);

        return $user === null ? null : AiCaller::staff($business, $user, $channel);
    }
}
