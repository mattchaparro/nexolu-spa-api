<?php

namespace App\Ai;

use App\Services\Ia\KnowledgeClient;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que se ofrece DESPUÉS de confirmar la cita.
 *
 * Es lo que hace hoy el bot de ManyChat y la clienta ya conoce: confirmada
 * la cita, un segundo mensaje con «Info de garantías», «Recomendaciones» y
 * «Cancelaciones». La diferencia es de dónde sale el texto: de la base de
 * conocimiento del negocio (el mismo que responde esas preguntas en la
 * conversación), no de un flujo aparte que alguien tiene que mantener al
 * día en dos sitios.
 *
 * Si el negocio no ha escrito ninguna de esas entradas, no se ofrece nada:
 * un botón que contesta "no tengo esa información" es peor que no estar.
 */
final class InfoPostCita
{
    /** Cuánto vive el menú de información: una cola de mensajes, no un día. */
    private const TTL_SEGUNDOS = 1800;

    /** Lo que se ofrece tras la cita, en este orden. */
    private const TEMAS = [
        'Info de garantías' => '/garant/iu',
        'Recomendaciones' => '/recomend|cuidado/iu',
        'Cancelaciones' => '/cancel/iu',
    ];

    /**
     * Ofrece los temas que el negocio SÍ tiene escritos.
     *
     * Devuelve false si no hay nada que ofrecer o el canal no pudo: quien
     * llama sigue su camino sin ruido.
     */
    public function ofrecer(AiCaller $caller, string $phone): bool
    {
        $entradas = $this->conocimiento($caller);

        if ($entradas === []) {
            return false;
        }

        $filas = [];
        $mapa = [];

        foreach ($entradas as $titulo => $respuesta) {
            $filas[] = ['id' => 'info'.count($filas), 'title' => $titulo];
            $mapa[$this->plano($titulo)] = $respuesta;
        }

        if (! app(EnvioDirecto::class)->opciones($caller, 'Puedes consultar información adicional 👇', $filas)) {
            return false;
        }

        Cache::put($this->clave($phone), $mapa, self::TTL_SEGUNDOS);

        return true;
    }

    /**
     * La respuesta de un tema tocado, o null si no era uno de estos botones.
     *
     * El caché guarda lo que se acaba de ofrecer, pero esos mismos botones
     * viajan TAMBIÉN en la plantilla de confirmación --que sale cuando la
     * clienta no ha escrito-- y ahí puede tocarlos tres días después, con el
     * caché vencido. Por eso, con el negocio a la mano, se vuelve a la base
     * de conocimiento en vez de dejar el botón muerto.
     */
    public function respuestaA(string $phone, string $texto, ?AiCaller $caller = null): ?string
    {
        $mapa = Cache::get($this->clave($phone), []);
        $clave = UltimoPedido::claveDe($mapa, $texto);

        if ($clave !== null) {
            return (string) $mapa[$clave];
        }

        if ($caller === null) {
            return null;
        }

        // Con las llaves en plano, como las guarda `ofrecer`: `claveDe`
        // normaliza el texto tocado, no las llaves del mapa.
        $vivos = [];

        foreach ($this->conocimiento($caller) as $titulo => $respuesta) {
            $vivos[$this->plano($titulo)] = $respuesta;
        }

        $clave = UltimoPedido::claveDe($vivos, $texto);

        return $clave === null ? null : (string) $vivos[$clave];
    }

    public function olvidar(string $phone): void
    {
        Cache::forget($this->clave($phone));
    }

    /**
     * Las entradas del negocio que responden estos temas, por título.
     *
     * Si el Core no responde, no se ofrece nada: la confirmación de la
     * cita ya salió y eso es lo que importa.
     *
     * @return array<string, string>
     */
    private function conocimiento(AiCaller $caller): array
    {
        $cliente = app(KnowledgeClient::class);

        if (! $cliente->isConfigured()) {
            return [];
        }

        try {
            $entradas = Cache::remember(
                'ia:conocimiento:'.$caller->business->id,
                600,
                fn () => $cliente->list($caller->business),
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $activas = collect($entradas)->filter(fn ($e) => ($e['is_active'] ?? false));
        $encontradas = [];

        foreach (self::TEMAS as $titulo => $patron) {
            $entrada = $activas->first(fn ($e) => preg_match($patron, (string) ($e['topic'] ?? '')) === 1);

            if ($entrada !== null) {
                $encontradas[$titulo] = (string) $entrada['answer'];
            }
        }

        return $encontradas;
    }

    private function clave(string $phone): string
    {
        return 'ia:info-post-cita:'.ltrim($phone, '+');
    }

    private function plano(string $texto): string
    {
        return trim(mb_strtolower(strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'])));
    }
}
