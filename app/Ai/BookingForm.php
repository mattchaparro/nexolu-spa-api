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

    /** ¿Hay un Flow publicado para confirmar citas? */
    public static function enabled(): bool
    {
        return trim((string) config('spa.whatsapp_booking_flow_id')) !== '';
    }

    /**
     * Manda el formulario pre-cargado en lugar de la lista de horas.
     *
     * La clienta recibe UNA pantalla: el resumen fijo (servicio y día),
     * la hora a elegir entre las libres de ese día, y su nombre — que es
     * donde los perfiles raros de WhatsApp por fin dicen cómo se llaman.
     * Confirmar ahí dispara el nfm_reply que `handle` convierte en cita.
     *
     * False = no se pudo (canal caído, sin Flow publicado): quien llama
     * cae a la lista de botones de siempre. Nada nuevo puede romper lo
     * que ya funcionaba.
     *
     * @param  array<string, array{hora_24: string, hora: string, con?: ?string}>  $horas
     */
    public function send(
        AiCaller $caller,
        string $servicio,
        string $dia,
        string $fechaIso,
        array $horas,
    ): bool {
        if (! self::enabled() || $horas === []) {
            return false;
        }

        $filas = [];

        foreach ($horas as $h) {
            $filas[] = [
                'id' => $h['hora_24'],
                'title' => $h['hora'].(empty($h['con']) ? '' : ' con '.$h['con']),
            ];
        }

        $nombre = trim((string) $caller->client?->fullName());

        return app(EnvioDirecto::class)->formulario(
            $caller,
            (string) config('spa.whatsapp_booking_flow_id'),
            'CONFIRMAR',
            sprintf('Tu cita de *%s* para el *%s* está casi lista: elige la hora y confirma 👇', $servicio, $dia),
            'Confirmar cita',
            [
                'resumen' => $servicio.' — '.$dia,
                'horas' => $filas,
                'servicio' => $servicio,
                'fecha' => $fechaIso,
                'hora' => $filas[0]['id'],
                // El nombre de la ficha solo si parece de persona: un
                // «🦋 Yess 🦋» pre-cargado invita a dejarlo así.
                'nombre' => NombreRaro::es($nombre) ? '' : $nombre,
            ],
        );
    }

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
            $this->preguntarSiMueve($caller, $envio, $phone, $argumentos, $resultado['ya_tiene_cita']);

            return;
        }

        // Callarse tras un formulario enviado es dejarla creyendo que quedó.
        $motivo = mb_strtolower(rtrim((string) ($resultado['motivo'] ?? 'esa hora se acabó de ocupar'), '.'));
        $envio->texto($caller, "No pude dejar la cita del formulario 😕 ({$motivo}). Escríbeme por aquí y la cuadramos.");
    }

    /**
     * Ya tenía una cita: los MISMOS botones del camino de botones.
     *
     * El primer día del formulario, Alejandro envió el suyo teniendo ya
     * una cita y recibió "escríbeme si quieres moverla" — texto plano,
     * cuando el flujo de botones responde con «Mover mi cita» / «Agendar
     * otra». El formulario no puede ser el camino con MENOS flujo. Se
     * deja el pedido armado igual que lo haría Toques y los toques
     * siguientes ya saben qué hacer (mover ejecuta reagendar).
     *
     * @param  array<string, mixed>  $argumentos
     * @param  array{id: int, servicio: string, dia: string, hora: string}  $existente
     */
    private function preguntarSiMueve(
        AiCaller $caller,
        EnvioDirecto $envio,
        string $phone,
        array $argumentos,
        array $existente,
    ): void {
        $tz = $caller->business->businessTimezone();
        $inicio = FechaDicha::resolver((string) $argumentos['fecha'], $tz)
            ?->setTimeFromTimeString($argumentos['hora'].':00');

        if ($inicio === null) {
            $envio->texto($caller, sprintf(
                'Ya tienes una cita de *%s* el *%s* a las *%s* 🤔 Escríbeme si quieres moverla, o si de verdad quieres otra aparte.',
                $existente['servicio'],
                $existente['dia'],
                $existente['hora'],
            ));

            return;
        }

        $dia = $inicio->locale('es')->isoFormat('dddd D [de] MMMM');
        $horaLegible = HoraLegible::de($inicio, $tz);

        UltimoPedido::guardar($phone, [
            'servicios' => [(string) $argumentos['servicio']],
            'fecha' => (string) $argumentos['fecha'],
            'dia' => $dia,
            'confirmar' => ['hora_24' => (string) $argumentos['hora'], 'hora' => $horaLegible],
            'decidir_mover' => $existente,
        ]);

        $enviado = $envio->opciones(
            $caller,
            sprintf(
                'Ya tienes una cita de *%s* el *%s* a las *%s* 🤔 ¿La muevo para el *%s* a las *%s*, o te agendo otra aparte?',
                $existente['servicio'],
                $existente['dia'],
                $existente['hora'],
                $dia,
                $horaLegible,
            ),
            [
                ['id' => 'mover', 'title' => Toques::MOVER],
                ['id' => 'otra_cita', 'title' => Toques::OTRA_CITA],
            ],
        );

        if (! $enviado) {
            $envio->texto($caller, sprintf(
                'Ya tienes una cita de *%s* el *%s* a las *%s* 🤔 Escríbeme si quieres moverla, o si de verdad quieres otra aparte.',
                $existente['servicio'],
                $existente['dia'],
                $existente['hora'],
            ));
        }
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
