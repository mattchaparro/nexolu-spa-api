<?php

namespace App\Services;

use App\Models\Client;

/**
 * Encuentra o crea el cliente de un servicio registrado por nombre.
 *
 * Vive aparte porque hay DOS caminos que registran servicios -- agendar una
 * cita y registrar a alguien que llego sin cita -- y ambos tienen que crear
 * ficha. Cuando la logica vivia dentro del controlador de citas, el walk-in
 * guardaba el nombre suelto y el cliente nunca aparecia en el listado ni
 * acumulaba historial.
 */
class ClientResolver
{
    /**
     * @param  string|null  $phone  Ya normalizado a E.164 sin '+'.
     */
    public function resolve(int $businessId, ?int $clientId, ?string $name, ?string $phone): ?Client
    {
        if ($clientId !== null) {
            return Client::withoutGlobalScope('business')
                ->where('business_id', $businessId)
                ->find($clientId);
        }

        if ($name === null || trim($name) === '') {
            return null;
        }

        // El telefono primero: es lo unico que distingue a dos clientes que se
        // llaman igual, y evita duplicar a la misma persona cada vez que
        // alguien escribe su nombre con otra tilde.
        if ($phone !== null) {
            $existing = Client::withoutGlobalScope('business')
                ->where('business_id', $businessId)
                ->where('phone', $phone)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $parts = preg_split('/\s+/', trim($name), 2);

        return Client::create([
            'business_id' => $businessId,
            'name' => $parts[0],
            'last_name' => $parts[1] ?? null,
            'phone' => $phone,
            'is_active' => true,
        ]);
    }
}
