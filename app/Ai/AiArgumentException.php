<?php

namespace App\Ai;

/**
 * Lo que pidio el modelo no se puede resolver, y el mensaje explica por que.
 *
 * No es un error de sistema: es una respuesta que el agente puede usar para
 * PREGUNTARLE a la clienta ("¿a cuál sede vas?"). Por eso el texto va escrito
 * para que un modelo lo lea y actue, no para un log.
 */
class AiArgumentException extends \RuntimeException {}
