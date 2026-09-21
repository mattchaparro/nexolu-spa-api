<?php

namespace App\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * "A este número no le mandes nada de verdad, que estoy evaluando".
 *
 * `ia:evaluar` conversa con el bot como si fuera una clienta, y el bot
 * contesta como le toca: mandando listas de horas y de servicios por
 * WhatsApp. Veintiocho conversaciones son una docena larga de mensajes
 * saliendo de verdad en cada corrida.
 *
 * El número que usa la evaluación es el de Alejandro -- si algo se
 * escapa, que llegue a él y no a una desconocida -- pero justamente por
 * eso los envíos se cortan antes de salir: no queremos llenarle el
 * teléfono cada vez que se mide el bot. Con `--enviar` la marca no se
 * pone y los mensajes llegan, que es como se revisa cómo se VEN.
 *
 * Va en caché y no en una variable porque quien manda no es este
 * proceso: la evaluación le habla al Core, el Core le pega al endpoint
 * de herramientas, y ese es otro request. La caché es lo único que los
 * dos ven.
 */
final class EsUnaPrueba
{
    /*
     * Corto a propósito. El número es el de una persona de verdad: si una
     * evaluación se corta a la mitad y la marca queda puesta, el bot deja
     * de contestarle en serio. La evaluación la renueva en cada caso, así
     * que dura lo que dure la corrida y no más de cinco minutos después.
     */
    private const TTL_SEGUNDOS = 300;

    /**
     * Lo que se le escribe a una cita nacida de una evaluación.
     *
     * La evaluación tiene que borrar lo que el bot dejó, y lo hace sobre
     * una ficha de verdad: borrar "todo lo creado en los últimos
     * segundos" casi nunca se equivoca, y "casi nunca" no alcanza cuando
     * lo que está en juego es la cita de alguien.
     */
    public const SELLO = '[evaluación ia:evaluar]';

    public static function marcar(string $phone): void
    {
        Cache::put(self::clave($phone), true, self::TTL_SEGUNDOS);
    }

    public static function olvidar(string $phone): void
    {
        Cache::forget(self::clave($phone));
    }

    public static function si(string $phone): bool
    {
        return (bool) Cache::get(self::clave($phone), false);
    }

    /**
     * Lo que el bot HABRÍA mandado, para que la prueba lo pueda leer.
     *
     * Las horas y los servicios le llegan a la clienta como listas que
     * manda el canal, no como texto del modelo. Si la prueba solo ve el
     * texto, no ve las listas -- y entonces la clienta simulada no puede
     * "tocar" una fila, que es justo lo que hace una persona.
     *
     * @param  array<string, mixed>  $envio
     */
    public static function registrar(string $phone, array $envio): void
    {
        $clave = self::clave($phone).':enviados';
        $lista = Cache::get($clave, []);
        $lista[] = $envio;
        Cache::put($clave, $lista, self::TTL_SEGUNDOS);
    }

    /**
     * Lo registrado desde la última vez que se preguntó. Leer limpia.
     *
     * @return list<array<string, mixed>>
     */
    public static function enviados(string $phone): array
    {
        return Cache::pull(self::clave($phone).':enviados', []);
    }

    private static function clave(string $phone): string
    {
        return 'ia:es-una-prueba:'.ltrim($phone, '+');
    }
}
