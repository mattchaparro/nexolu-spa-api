<?php

namespace App\Services\Scheduling\Exceptions;

/**
 * Se pidio agendar fuera de la jornada del recurso: antes de abrir, despues de
 * cerrar, en un dia que no trabaja, o encima de un almuerzo.
 *
 * Distinta de SlotUnavailableException, que significa "ese hueco ya lo tomo
 * alguien". Aca el hueco no existe, y ofrecer "elige otro" seria enganoso: hay
 * que mirar el horario, no la agenda.
 */
class OutsideWorkingHoursException extends \DomainException {}
