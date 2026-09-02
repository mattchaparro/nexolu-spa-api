<?php

namespace App\Ai;

use App\Models\Business;
use App\Models\Client;
use App\Models\User;

/**
 * Quien esta preguntando, ya resuelto contra la base de datos.
 *
 * El Spa tiene DOS clases de interlocutor, y confundirlos seria el peor bug
 * posible de este producto:
 *
 *  - EMPLEADA (`$user`): entra por el panel, tiene permisos, ve lo del
 *    negocio segun su rol y su sede.
 *  - CLIENTA (`$phone` + quiza `$client`): escribe por WhatsApp. NO es
 *    usuaria del sistema, no tiene permisos, y solo puede tocar LO SUYO.
 *
 * La clienta puede no tener ficha todavia -- una primera vez es un telefono
 * sin nada detras -- asi que `client` es opcional mientras `phone` no lo es.
 */
final class AiCaller
{
    private function __construct(
        public readonly Business $business,
        public readonly ?User $user,
        public readonly ?Client $client,
        public readonly ?string $phone,
        public readonly string $channel,
    ) {}

    public static function staff(Business $business, User $user, string $channel): self
    {
        return new self($business, $user, null, null, $channel);
    }

    public static function customer(Business $business, string $phone, ?Client $client, string $channel): self
    {
        return new self($business, null, $client, $phone, $channel);
    }

    /** Una empleada del negocio, con permisos de verdad. */
    public function isStaff(): bool
    {
        return $this->user !== null;
    }

    /**
     * Una clienta escribiendo desde afuera.
     *
     * Todo lo que devuelva una capacidad para ella tiene que ser suyo o
     * publico. No hay un tercer caso.
     */
    public function isCustomer(): bool
    {
        return $this->user === null;
    }
}
