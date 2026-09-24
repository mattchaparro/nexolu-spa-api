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
    public function resolve(
        int $businessId,
        ?int $clientId,
        ?string $name,
        ?string $phone,
        ?string $email = null,
    ): ?Client {
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
            /*
             * El mismo numero escrito de otra forma sigue siendo el mismo.
             *
             * `normalize` devuelve solo digitos (573001112233), pero en la base
             * conviven otros formatos: las fichas que vienen del sistema viejo
             * traen "+57...", y las de la pagina publica se guardaron alguna
             * vez con espacios. Comparando literal, la misma persona entraba
             * otra vez como ficha NUEVA -- sin su historial, sin sus sellos y
             * sin su encuesta.
             *
             * Se descubrio probando a mano: identificar a Gisel por su
             * telefono decia "es Gisel M." y la visita terminaba en una ficha
             * repetida.
             */
            $existing = Client::withoutGlobalScope('business')
                ->where('business_id', $businessId)
                ->whereIn('phone', self::variantes($phone))
                ->first();

            if ($existing !== null) {
                /*
                 * El correo se COMPLETA, no se pisa.
                 *
                 * Quien reserva por la pagina publica solo prueba que tiene el
                 * telefono a mano. Dejar que ese formulario reescriba el correo
                 * de una ficha que ya lo tenia es dejar que cualquiera cambie
                 * el contacto de un cliente ajeno.
                 */
                if ($email !== null && ($existing->email === null || $existing->email === '')) {
                    $existing->update(['email' => $email]);
                }

                return $existing;
            }
        }

        $parts = preg_split('/\s+/', trim($name), 2);

        return Client::create([
            'business_id' => $businessId,
            'name' => $parts[0],
            'last_name' => $parts[1] ?? null,
            'phone' => $phone,
            'email' => $email,
            'is_active' => true,
        ]);
    }

    /**
     * Las formas en que ESE numero puede estar escrito en la base.
     *
     * No es adivinar: son las tres que existen de verdad. El normalizado
     * (573001112233), el mismo con "+" --como lo trae el sistema viejo-- y el
     * nacional sin indicativo (3001112233), que es como lo escribe quien lo
     * anota a mano.
     *
     * @return list<string>
     */
    private static function variantes(string $phone): array
    {
        $variantes = [$phone, '+'.$phone];

        // Sin el indicativo: 57 + 10 digitos en Colombia. Se recorta por
        // longitud y no por pais para no atarlo a una tabla de indicativos.
        if (strlen($phone) > 10) {
            $nacional = substr($phone, -10);
            $variantes[] = $nacional;
            $variantes[] = '+'.$nacional;
        }

        return array_values(array_unique($variantes));
    }
}
