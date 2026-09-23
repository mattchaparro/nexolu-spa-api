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
     * Las que traen BOTONES, y por eso salen como plantilla siempre.
     *
     * Dentro de las 24 horas el texto libre suele ser mejor --lleva enlaces y
     * renglones que una plantilla aprobada no puede tener-- pero no puede
     * reproducir un boton de respuesta rapida. Mandar el recordatorio de
     * retoque como texto le quita «Agendar retoque», que es justamente el
     * atajo que hace que ese mensaje sirva.
     *
     * @var list<string>
     */
    private const CON_BOTONES = ['retoque_recordatorio'];

    /** @param  string|null  $name  el `template_name` de la fila de la bandeja */
    public static function hasButtons(?string $name): bool
    {
        return $name !== null && in_array($name, self::CON_BOTONES, true);
    }

    /**
     * @param  list<string>  $params  en el orden en que la plantilla los espera
     */
    private function __construct(
        public readonly string $name,
        public readonly string $language,
        public readonly array $params,
    ) {}

    /**
     * Una plantilla que eligio el negocio, no el codigo.
     *
     * Las de arriba son nuestras y sus variables estan fijas. Una difusion
     * usa la que el negocio aprobo en Meta, con las variables que le puso, y
     * el sistema no puede saber cuales son.
     *
     * @param  list<string>  $params
     */
    public static function raw(string $name, string $language, array $params): self
    {
        return new self($name, $language ?: 'es', array_values($params));
    }

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

    /**
     * "Ya casi te toca retoque".
     *
     * Sale semanas despues de la ultima conversacion, asi que SIEMPRE va
     * como plantilla: fuera de la ventana de 24h Meta descarta el texto
     * libre sin avisar.
     */
    public static function retoque(string $cliente, string $negocio, string $servicio): self
    {
        return new self('retoque_recordatorio', 'es', [$cliente, $negocio, $servicio]);
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
