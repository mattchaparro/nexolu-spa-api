<?php

namespace App\Services\Scheduling\Exceptions;

use RuntimeException;

/**
 * El horario dejo de estar libre entre que se mostro y que se intento tomar.
 * No es un error de programacion: es el resultado normal de una carrera que la
 * base de datos resolvio a favor de otro. Se traduce a 409 en la API.
 */
class SlotUnavailableException extends RuntimeException {}
