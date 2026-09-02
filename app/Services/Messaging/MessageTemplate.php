<?php

namespace App\Services\Messaging;

/**
 * Una plantilla aprobada de WhatsApp, con sus variables ya resueltas.
 *
 * Existe para que el nombre y el ORDEN de las variables viajen juntos. Meta
 * no recibe nombres: recibe una lista posicional, y meter un parametro en el
 * puesto equivocado manda "tu cita en miercoles 3 a las Luxury Nails" sin que
 * nada falle.
 */
final class MessageTemplate
{
    /**
     * @param  list<string>  $params  en el orden en que la plantilla los espera
     */
    private function __construct(
        public readonly string $name,
        public readonly string $language,
        public readonly array $params,
    ) {}

    /**
     * El recordatorio de una cita.
     *
     * El NOMBRE DEL NEGOCIO va adentro a proposito: con el numero compartido
     * de Nexolu el mensaje no llega del telefono del spa, asi que si el texto
     * no dice de quien es, la clienta recibe un recordatorio de un
     * desconocido.
     */
    public static function recordatorio(
        string $cliente,
        string $negocio,
        string $fecha,
        string $hora,
    ): self {
        return new self('recordatorio_cita', 'es', [$cliente, $negocio, $fecha, $hora]);
    }

    /** La confirmacion, con el mismo formato que la clienta ya conoce. */
    public static function confirmacion(
        string $fecha,
        string $hora,
        string $servicio,
        string $precio,
        string $profesional,
        string $negocio,
    ): self {
        return new self('confirmacion_cita', 'es', [$fecha, $hora, $servicio, $precio, $profesional, $negocio]);
    }
}
