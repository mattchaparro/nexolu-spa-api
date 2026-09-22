<?php

namespace App\Ai;

use App\Ai\Capabilities\CreateAppointmentCapability;
use App\Ai\Capabilities\SaveContactCapability;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;
use Carbon\CarbonImmutable;

/**
 * El formulario nativo de WhatsApp que confirma (o arma) la cita.
 *
 * La idea de Alejandro: la IA reúne los datos conversando y, al final, la
 * clienta no confirma leyendo un texto sino REVISANDO un formulario
 * pre-cargado — servicio, fecha con su selector nativo, hora, nombre —
 * donde puede corregir cualquier campo antes de enviar. Meta lo llama
 * Flow; para la clienta es una pantalla de "revisa y confirma".
 *
 * Esto procesa la RESPUESTA de ese formulario (el `nfm_reply` del
 * webhook, que Connect reenvía intacto). El contrato con el Flow JSON
 * publicado (docs/whatsapp-flows/confirmar-cita.json) es el payload que
 * el Flow entrega al completarse:
 *
 *   { "pedido": "cita", "servicio": "...", "fecha": <YYYY-MM-DD o epoch
 *     ms del DatePicker>, "hora": "HH:MM", "nombre"?: "...",
 *     "para_quien"?: "..." }
 *
 * La reserva es la misma de siempre (`crear_cita`, con todas sus
 * guardas: quién está libre, la cita repetida, el sello de pruebas) y la
 * confirmación la manda la propia herramienta. Aquí solo se traduce el
 * formulario a esa llamada y se avisa con honestidad cuando no se pudo.
 */
final class BookingForm
{
    public function __construct(
        private readonly CreateAppointmentCapability $booking,
        private readonly SaveContactCapability $contact,
    ) {}

    /**
     * ¿Este formulario es el nuestro de agendar?
     *
     * Otros Flows (encuestas, formularios de un flujo de Connect) llegan
     * por el mismo webhook: sin la marca, no son asunto de esta clase.
     *
     * @param  array<string, mixed>  $respuesta
     */
    public static function isBookingReply(array $respuesta): bool
    {
        return ($respuesta['pedido'] ?? null) === 'cita';
    }

    /**
     * @param  array<string, mixed>  $respuesta
     */
    public function handle(WhatsappConversation $conversacion, array $respuesta): void
    {
        $business = $conversacion->business;
        $phone = ChannelPhone::normalize((string) $conversacion->phone, $business->country_code ?? 'CO');

        if ($phone === null) {
            return;
        }

        $caller = AiCaller::customer($business, $phone, $conversacion->client, 'whatsapp');
        $envio = app(EnvioDirecto::class);

        /*
         * El nombre del formulario corrige la ficha ANTES de agendar: es
         * el campo pensado justo para los perfiles raros de WhatsApp
         * (NombreRaro) — si lo escribió, es porque así se llama.
         */
        $nombre = trim((string) ($respuesta['nombre'] ?? ''));

        if ($nombre !== '' && ! NombreRaro::es($nombre)) {
            try {
                $this->contact->execute($caller, ['nombre' => $nombre]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $argumentos = array_filter([
            'servicio' => $respuesta['servicio'] ?? null,
            'fecha' => $this->fecha($respuesta['fecha'] ?? null, $business->businessTimezone()),
            'hora' => $respuesta['hora'] ?? null,
            'para_quien' => trim((string) ($respuesta['para_quien'] ?? '')) ?: null,
            'cliente' => $nombre !== '' ? $nombre : null,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $resultado = $this->booking->execute($caller, $argumentos);
        } catch (\Throwable $e) {
            report($e);
            $envio->texto($caller, 'Recibí tu formulario, pero no pude dejar la cita 😕 Escríbeme por aquí y la cuadramos.');

            return;
        }

        if ($resultado['agendada'] ?? false) {
            // La confirmación ya la mandó la herramienta de reserva.
            UltimoPedido::olvidar($phone);

            return;
        }

        if (! empty($resultado['ya_existia'])) {
            $envio->texto($caller, sprintf(
                'Esa cita ya la tienes agendada 😊 *%s* el *%s* a las *%s*. Te esperamos 💅',
                $resultado['cita']['servicio'],
                $resultado['cita']['dia'],
                $resultado['cita']['hora'],
            ));

            return;
        }

        if (! empty($resultado['ya_tiene_cita'])) {
            $cita = $resultado['ya_tiene_cita'];
            $envio->texto($caller, sprintf(
                'Ya tienes una cita de *%s* el *%s* a las *%s* 🤔 Escríbeme si quieres moverla, o si de verdad quieres otra aparte.',
                $cita['servicio'],
                $cita['dia'],
                $cita['hora'],
            ));

            return;
        }

        // Callarse tras un formulario enviado es dejarla creyendo que quedó.
        $motivo = mb_strtolower(rtrim((string) ($resultado['motivo'] ?? 'esa hora se acabó de ocupar'), '.'));
        $envio->texto($caller, "No pude dejar la cita del formulario 😕 ({$motivo}). Escríbeme por aquí y la cuadramos.");
    }

    /**
     * El DatePicker nativo manda milisegundos de época; el resto, texto.
     */
    private function fecha(mixed $valor, string $tz): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor) && (int) $valor > 100_000_000_000) {
            return CarbonImmutable::createFromTimestampMs((int) $valor, 'UTC')
                ->setTimezone($tz)
                ->format('Y-m-d');
        }

        return (string) $valor;
    }
}
