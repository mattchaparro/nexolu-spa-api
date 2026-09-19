<?php

namespace App\Ai;

/**
 * Lo que pidio el modelo no se puede resolver, y el mensaje explica por que.
 *
 * No es un error de sistema: es una respuesta que el agente puede usar para
 * PREGUNTARLE a la clienta ("¿a cuál sede vas?"). Por eso el texto va escrito
 * para que un modelo lo lea y actue, no para un log.
 *
 * Cuando lo que falta es ELEGIR entre cosas que existen, viene tambien la
 * lista en `opciones`. Ahi la pregunta no se escribe: se manda tocable, que
 * es la diferencia entre "¿cual de estas cinco?" y cinco botones.
 */
class AiArgumentException extends \RuntimeException
{
    /** @param list<string> $opciones */
    public function __construct(string $message, public readonly array $opciones = [])
    {
        parent::__construct($message);
    }
}
