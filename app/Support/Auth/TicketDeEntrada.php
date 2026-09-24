<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * El pase de un solo uso con el que el sistema viejo entrega a alguien ya
 * identificado.
 *
 * La manicurista entra por `luxurynails.com.co`, que es la dirección que se
 * sabe de memoria, y aterriza en la aplicación nueva sin volver a escribir
 * su clave. Mientras dure la convivencia eso evita lo único que de verdad
 * frena una migración: pedirle a la gente que cambie por dónde entra.
 *
 * El pase viaja en la URL --y por eso dura poco y se gasta al usarse-- pero
 * la llave compartida NO: esa solo se usa entre los dos servidores, al
 * pedirlo. Un enlace copiado del historial del navegador no sirve para nada
 * porque ya se consumió.
 *
 * Vive en caché y no en una tabla porque es de un momento: si no se canjea
 * en un minuto, no hay nada que conservar.
 */
final class TicketDeEntrada
{
    /*
     * Un minuto: lo que tarda un navegador en seguir una redirección, con
     * margen para una conexión mala. Más que eso solo alarga la ventana en
     * la que un enlace filtrado todavía valdría.
     */
    private const SEGUNDOS = 60;

    public static function emitir(User $user): string
    {
        $ticket = Str::random(64);

        Cache::put(self::clave($ticket), $user->id, self::SEGUNDOS);

        return $ticket;
    }

    /**
     * Devuelve al usuario y GASTA el pase, o null si no sirve.
     *
     * El borrado va antes de buscar al usuario a propósito: si dos
     * peticiones llegan a la vez --el doble envío de un navegador
     * impaciente-- solo una encuentra el pase y la otra se queda sin nada.
     */
    public static function canjear(string $ticket): ?User
    {
        $clave = self::clave($ticket);
        $userId = Cache::pull($clave);

        if ($userId === null) {
            return null;
        }

        return User::withoutGlobalScopes()->find($userId);
    }

    private static function clave(string $ticket): string
    {
        return 'auth:ticket-de-entrada:'.hash('sha256', $ticket);
    }
}
