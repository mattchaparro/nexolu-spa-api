<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\OpcionesEnviadas;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\WhatsApp\NexoluCommsChannel;
use App\Support\ChannelPhone;
use App\Support\TituloCorto;

/**
 * Ofrecerle a la clienta opciones que pueda TOCAR.
 *
 * El agente escribía "tengo a las 10, a la 1 y a las 5" y esperaba que
 * alguien transcribiera una. A la gente le da pereza leer y más pereza
 * escribir: tocar "3 pm" es un gesto, y ahí es donde se gana o se pierde
 * la cita.
 *
 * Esta herramienta ENVÍA el mensaje (no devuelve texto para que el modelo
 * lo escriba) y deja una marca (`OpcionesEnviadas`) para que el job no
 * escriba encima: si no, la clienta recibiría la lista y debajo el mismo
 * contenido repetido en texto. La marca manda sobre lo que diga el modelo,
 * porque pedirle que responda vacío funciona *a veces*.
 *
 * Sirve para horas, para servicios y para confirmar ("Sí" / "Cambiar la
 * hora"). Es deliberadamente genérica: una herramienta por cada caso
 * multiplicaría las formas de equivocarse sin agregar nada.
 */
class OfferOptionsCapability implements Capability
{
    public function __construct(private readonly NexoluCommsChannel $channel) {}

    public function requiredPermission(): ?string
    {
        return null;
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function allowsCustomers(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mensaje' => ['required', 'string', 'max:900'],
            'opciones' => ['required', 'array', 'min:1', 'max:10'],
            // Meta corta los titulos en 24 caracteres, pero eso se resuelve
            // recortando (TituloCorto), no rechazando: el modelo escribia "6 pm
            // con Anyi Ruiz", la validacion fallaba y la clienta leia "no pude
            // consultar la agenda" por una fila dos letras mas larga.
            'opciones.*' => ['required', 'string', 'max:120'],
            'boton' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $phone = ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');

        if ($phone === null) {
            return [
                'mostrado' => false,
                'instruccion' => 'No pude mostrar opciones. Escríbelas en el texto de tu respuesta.',
            ];
        }

        /*
         * Una sola lista por turno.
         *
         * Pasó en producción: `disponibilidad` mostró las horas como
         * botones y el modelo, además, llamó acá con las mismas. La
         * clienta recibió la misma lista dos veces seguidas. Que no se
         * repita no puede depender de que el modelo lea la instrucción.
         */
        if (OpcionesEnviadas::yaSeMandaron($phone)) {
            return [
                'mostrado' => false,
                'instruccion' => 'Ya le mostraste opciones en este turno y las está viendo. '
                    .'No mandes otra lista: responde con una cadena vacía.',
            ];
        }

        $opciones = [];
        foreach (array_values($arguments['opciones']) as $i => $titulo) {
            $opciones[] = ['id' => 'op'.$i, 'title' => TituloCorto::de($titulo)];
        }

        $enviado = $this->channel->sendOptions(
            $phone,
            $arguments['mensaje'],
            $opciones,
            $caller->business->id,
            $arguments['boton'] ?? 'Ver opciones',
        );

        if (! $enviado) {
            return [
                'mostrado' => false,
                'instruccion' => 'No pude mostrar opciones. Escríbelas en el texto de tu respuesta.',
            ];
        }

        OpcionesEnviadas::marcar($phone);
        $this->guardarEnElHilo($caller, $phone, $arguments['mensaje'], $opciones);

        return [
            'mostrado' => true,
            'instruccion' => 'Las opciones YA le llegaron y las puede tocar. No repitas el '
                .'mensaje ni las escribas otra vez: responde con una cadena vacía.',
        ];
    }

    /**
     * Que quien abra la bandeja vea lo que se le ofreció.
     *
     * Sin esto, la conversación muestra la pregunta de la clienta y después
     * su respuesta ("3 pm") sin que se entienda de dónde salió.
     *
     * @param  list<array{id: string, title: string}>  $opciones
     */
    private function guardarEnElHilo(AiCaller $caller, string $phone, string $mensaje, array $opciones): void
    {
        $conversacion = WhatsappConversation::withoutGlobalScope('business')
            ->where('business_id', $caller->business->id)
            ->where('phone', $phone)
            ->first();

        if ($conversacion === null) {
            return;
        }

        Message::create([
            'business_id' => $conversacion->business_id,
            'conversation_id' => $conversacion->id,
            'client_id' => $conversacion->client_id,
            'kind' => Message::KIND_AGENT,
            'direction' => Message::DIRECTION_OUT,
            'to' => $phone,
            'body' => $mensaje."\n\n".collect($opciones)->pluck('title')->map(fn ($t) => "▸ {$t}")->implode("\n"),
            // Ya salió por Connect en esta misma llamada.
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $conversacion->update(['last_message_at' => now()]);
    }
}
