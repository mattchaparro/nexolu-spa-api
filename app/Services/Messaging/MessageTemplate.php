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

    /**
     * "Gracias por tu visita", con el estado de su tarjeta de sellos.
     *
     * Sale al terminar el servicio, y casi nunca hay ventana abierta: la
     * clienta vino al salon, no escribio por WhatsApp. Por eso existe esta
     * plantilla -- sin ella, el mensaje que mas hace volver no se entrega.
     */
    public static function gracias(
        string $cliente,
        string $servicio,
        string $fecha,
        string $sellos,
        string $faltan,
        string $premio,
    ): self {
        return new self('resumen_de_tu_visita', 'es', [$cliente, $servicio, $fecha, $sellos, $faltan, $premio]);
    }

    /**
     * "Te agendaron una cita": el aviso a quien va a atender.
     *
     * Casi siempre fuera de la ventana: la manicurista no le escribe al
     * numero del salon, lo usa para recibir. Por eso el aviso al equipo
     * nace con plantilla, a diferencia de los de la clienta.
     */
    public static function equipoAgendada(
        string $profesional,
        string $cliente,
        string $servicio,
        string $fecha,
        string $hora,
    ): self {
        return new self('cita_nueva_equipo', 'es', [$profesional, $cliente, $servicio, $fecha, $hora]);
    }

    /**
     * "Tu cita quedó cancelada", cuando la cancela el SALON.
     *
     * Cancelar del lado del negocio pasa fuera de toda conversacion --se
     * enfermo quien atendia, se cayo la luz-- asi que la ventana esta
     * cerrada casi siempre. Sin plantilla, la clienta se aparece a una cita
     * que ya no existe.
     */
    public static function cancelacion(
        string $cliente,
        string $fecha,
        string $hora,
        string $negocio,
    ): self {
        return new self('cita_cancelada', 'es', [$cliente, $fecha, $hora, $negocio]);
    }

    /**
     * "Se libero un cupo": la lista de espera.
     *
     * El mas urgente de todos y el que mas depende de la plantilla: se avisa
     * dias despues de que la persona pidio el cupo, y es para quien lo tome
     * primero.
     */
    public static function cupoLibre(
        string $cliente,
        string $servicio,
        string $fecha,
        string $hora,
        string $negocio,
    ): self {
        return new self('aviso_lista_de_espera', 'es', [$cliente, $servicio, $fecha, $hora, $negocio]);
    }

    /**
     * "Te movieron una cita": misma persona, otra hora.
     *
     * `antes` y `ahora` llevan el dia y la hora juntos ("Jueves 17 a las 3:00
     * pm"). Dos variables y no cuatro: lo que ella compara es un momento
     * contra otro, no cuatro datos sueltos.
     */
    public static function equipoMovida(
        string $profesional,
        string $cliente,
        string $servicio,
        string $antes,
        string $ahora,
    ): self {
        return new self('cita_movida_equipo', 'es', [$profesional, $cliente, $servicio, $antes, $ahora]);
    }

    /** "Te cancelaron una cita", con la hora que queda libre. */
    public static function equipoCancelada(
        string $profesional,
        string $cliente,
        string $servicio,
        string $fecha,
        string $hora,
    ): self {
        return new self('cita_cancelada_equipo', 'es', [$profesional, $cliente, $servicio, $fecha, $hora]);
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
