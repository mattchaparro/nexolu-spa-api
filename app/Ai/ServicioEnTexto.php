<?php

namespace App\Ai;

use App\Models\Service;

/**
 * Los servicios que la clienta NOMBRÓ en su último mensaje.
 *
 * Mismo principio que DateInText: lo que ella escribió se captura en código
 * antes de que nadie lo interprete, porque el modelo resume y al resumir se
 * come palabras. Julián escribió «quiero agendar una cita de
 * semipermanente» y enseguida «pero de hombre»; el modelo llamó a la
 * herramienta con `servicio: semipermanente` y el bot iba a agendarle el
 * de mujer. Son veinte mil pesos de diferencia, y el cobro sale de lo que
 * quedó en la cita.
 *
 * Solo se guardan nombres, no una elección: decidir cuál aplica es de
 * UltimoPedido::completar, que sabe qué pidió el modelo. Ver
 * `masEspecifico`.
 */
final class ServicioEnTexto
{
    /*
     * Lo mismo que la fecha escrita: lo que dura una conversación de
     * agendar. Pasado eso, un «de hombre» de hace media hora no puede
     * cambiar un pedido nuevo.
     */
    private const SEGUNDOS = 600;

    public static function remember(string $phone, string $texto, int $businessId): void
    {
        $candidatos = ComoLoPide::candidatos(self::catalogo($businessId), $texto);

        if ($candidatos->isEmpty()) {
            return;
        }

        UltimoPedido::guardar($phone, [
            ...UltimoPedido::ver($phone),
            'servicios_dichos' => $candidatos->pluck('name')->values()->all(),
            'servicios_dichos_hasta' => now()->getTimestamp() + self::SEGUNDOS,
        ]);
    }

    /**
     * La versión más específica de lo que pidió el modelo, si la clienta la
     * nombró. Null si no hay una sola que lo sea.
     *
     * «Más específica» es que CONTIENE lo que dijo el modelo: «Semipermanente
     * Hombre» contiene «semipermanente». Solo eso se corrige. Si la clienta
     * nombró algo que no tiene que ver --habló de otro servicio de pasada--,
     * el modelo pudo tener buenas razones y no se le pasa por encima.
     *
     * Y tiene que ser UNA: si «semipermanente» cabe en dos nombres dichos,
     * elegir cualquiera sería adivinar, y para adivinar ya está el modelo.
     *
     * @param  list<string>  $dichos
     */
    public static function masEspecifico(string $delModelo, array $dichos): ?string
    {
        $base = self::plano($delModelo);

        if ($base === '') {
            return null;
        }

        $mas = array_values(array_filter(
            $dichos,
            fn (string $nombre) => self::plano($nombre) !== $base && str_contains(self::plano($nombre), $base),
        ));

        return count($mas) === 1 ? $mas[0] : null;
    }

    private static function catalogo(int $businessId)
    {
        return Service::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->with('category')
            ->get();
    }

    private static function plano(string $texto): string
    {
        return trim(mb_strtolower(strtr(
            $texto,
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'],
        )));
    }
}
