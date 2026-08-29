<?php

namespace App\Services\Scheduling\Exceptions;

/**
 * Una accion CRITICA de una etapa no pudo completarse, asi que la transicion
 * entera se deshace.
 *
 * Se lanza tanto cuando la accion revienta como cuando devuelve `failed`. Que
 * el fallo llegue como excepcion o como resultado es un detalle de quien la
 * escribio; el contrato -- "si esto falla, la cita no se mueve" -- tiene que
 * valer en los dos casos.
 */
class StageActionFailedException extends \DomainException {}
