<?php

namespace App\Jobs;

use App\Ai\DateInText;
use App\Ai\GuidedEntry;
use App\Ai\OpcionesEnviadas;
use App\Ai\Repetido;
use App\Ai\ServicioEnTexto;
use App\Ai\Toques;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\Ia\IaCoreClient;
use App\Services\Messaging\MessageDispatcher;
use App\Support\ChannelPhone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pensar la respuesta del agente, FUERA de la petición del webhook.
 *
 * No es una optimización, son tres problemas distintos que solo se
 * arreglan así:
 *
 * 1. EL ABRAZO MORTAL. Preguntarle al Core es una llamada HTTP que el Core
 *    devuelve con OTRA llamada HTTP a esta misma API (`/api/ai/tools/invoke`,
 *    para consultar disponibilidad o agendar). Si el proceso web esta
 *    bloqueado esperando al Core, no puede atender esa vuelta: se traban
 *    mutuamente hasta que algo expira. Con el trabajo en la cola, el proceso
 *    web queda libre para servir la herramienta mientras el worker espera.
 *
 * 2. EL REINTENTO DUPLICADO. Communications espera un 200 rapido; si tarda,
 *    reintenta el MISMO evento -- y reintentar aca es volver a escribirle a
 *    la clienta. Contestar de inmediato y pensar despues cierra esa puerta.
 *
 * 3. LA GENTE ESCRIBE EN PEDAZOS. "requiero una cita para hombre" / "pero a
 *    las 10" / "no se si se pueda con Angy": cuatro mensajes, UNA idea.
 *    Contestar cada uno produce cuatro respuestas incompletas -- y cuatro
 *    llamadas al modelo. Por eso el trabajo se programa con retraso y solo
 *    corre el ULTIMO: los pedazos se leen juntos, como los leeria una
 *    persona.
 *
 * Un modelo mas una o dos vueltas de herramienta se van facil a mas de 30
 * segundos, que es justo el tope de ejecucion de PHP en una petición web.
 */
class AnswerWhatsappMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * Dos intentos, no tres.
     *
     * Al otro lado hay una persona mirando el chat: un reintento tardio deja
     * de ser util y empieza a ser raro -- una respuesta a algo que ya dejo de
     * preguntar hace cinco minutos.
     */
    public int $tries = 2;

    /** Cuánto antes del último mensaje un pedazo sigue siendo "la misma idea". */
    private const PEDAZOS_MINUTOS = 10;

    /** @var list<int> */
    public array $backoff = [10];

    /**
     * Solo los rieles (menú, botones), nunca el modelo.
     *
     * Para cuando el bot está en pausa porque se pidió una persona, pero
     * ninguna ha contestado todavía: la clienta escribió "quiero agendar
     * una cita" y nadie le respondió en dos horas. Lo que se resuelve con
     * botones se sigue atendiendo; la conversación abierta, no -- esa es
     * de la persona que viene.
     *
     * Propiedad aparte y con valor por defecto (no promovida en el
     * constructor): un trabajo encolado antes de este cambio se
     * deserializa sin ella, y una propiedad readonly sin inicializar
     * revienta al leerla.
     */
    public bool $soloRieles = false;

    public function __construct(
        private readonly int $conversationId,
        /**
         * El id del mensaje que programo este trabajo.
         *
         * Distingue al ultimo de los que quedaron obsoletos: si entro algo
         * despues, este ya no tiene la ultima palabra y se retira en
         * silencio. Es un ID y no una marca de tiempo porque dos mensajes
         * pueden entrar en el mismo segundo -- y entonces los dos se
         * creerian el ultimo y contestarian los dos.
         */
        private readonly int $mensajeId,
        bool $soloRieles = false,
    ) {
        $this->soloRieles = $soloRieles;
    }

    public function handle(
        IaCoreClient $ia,
        MessageDispatcher $dispatcher,
    ): void {
        $conversacion = WhatsappConversation::withoutGlobalScope('business')
            ->with('business', 'client')
            ->find($this->conversationId);

        if ($conversacion === null) {
            return;
        }

        // Llego otro mensaje despues de este: el trabajo que programo ESE
        // es el que va a contestar, con todos los pedazos juntos.
        if ($this->ultimoEntranteId($conversacion) !== $this->mensajeId) {
            return;
        }

        $pendientes = $this->pendientes($conversacion);

        if ($pendientes === '') {
            return;
        }

        /*
         * La fecha y la franja que la clienta ESCRIBIO se capturan en
         * codigo antes de que nadie las interprete: mandan sobre lo que
         * el modelo ponga en sus argumentos (ver DateInText).
         */
        $phoneCtx = ChannelPhone::normalize(
            (string) $conversacion->phone,
            $conversacion->business->country_code ?? 'CO',
        );

        if ($phoneCtx !== null) {
            DateInText::remember($phoneCtx, $pendientes);

            // Y el servicio, por la misma razon: el modelo resume y al
            // resumir se come el «de hombre». Ver ServicioEnTexto.
            ServicioEnTexto::remember($phoneCtx, $pendientes, (int) $conversacion->business_id);
        }

        /*
         * La marca de "ya salieron botones" es de ESTE turno, no del
         * anterior. Nadie la borraba al empezar: el iniciador mandaba sus
         * botones, la marca quedaba viva tres minutos, y la respuesta al
         * toque siguiente -- el link de «Agendar en la web» -- se descartaba
         * en silencio. Alejandro tocó el botón y no recibió nada.
         */
        OpcionesEnviadas::consumir($conversacion->phone);

        /*
         * Un boton tocado -- una hora, un servicio, "Si, agendar" -- es un
         * dato que ya conocemos: no se le pide al modelo que lo interprete.
         * El arranque generico ("hola, quiero una cita") tampoco necesita
         * modelo: le llega el iniciador (GuidedEntry). Solo el texto libre
         * de verdad habla con el modelo.
         */
        $respuesta = app(Toques::class)->atender($conversacion, $pendientes)
            ?? app(GuidedEntry::class)->attend($conversacion, $pendientes, explicitOnly: $this->soloRieles);

        // Lo que redacta el CÓDIGO sale siempre; las guardas de abajo
        // (botones ya enviados, eco) son para el texto del modelo.
        $delCodigo = $respuesta !== null;

        if ($this->soloRieles) {
            // En pausa esperando a una persona: sin riel que lo resuelva,
            // silencio -- la conversación abierta es de quien viene.
            if (! $delCodigo) {
                return;
            }

            // Un riel la atendió: el bot vuelve, y la persona que venga
            // igual ve todo el hilo en la bandeja.
            $conversacion->resumeAgent();
        }

        $respuesta ??= $ia->ask($conversacion, $pendientes);

        if ($respuesta === null) {
            /*
             * Sin respuesta no se inventa una. Un "disculpa, no entendi"
             * automatico ante una caida del Core le enseña a la clienta que
             * el bot no sirve; el silencio deja que una persona conteste.
             */
            Log::warning('agente: el Core no respondio', [
                'conversation_id' => $conversacion->id,
            ]);

            return;
        }

        if ($respuesta['conversation_id'] !== null) {
            $conversacion->update(['ia_conversation_id' => $respuesta['conversation_id']]);
        }

        /*
         * Una herramienta ya le contesto: `disponibilidad` y
         * `ofrecer_opciones` mandan los botones ellas mismas. Mandar
         * ademas el texto del modelo le llegaria a la clienta como la
         * lista y, debajo, lo mismo escrito.
         *
         * La marca manda sobre el texto: pedirle al modelo que responda
         * vacio funciona a veces, y "a veces" en un chat con clientas es
         * un mensaje duplicado cada tres conversaciones.
         */
        if (trim($respuesta['text']) === '') {
            return;
        }

        if (! $delCodigo && OpcionesEnviadas::consumir($conversacion->phone)) {
            return;
        }

        /*
         * Si lo que va a salir es un eco de lo que ya dijo, el modelo
         * perdio el hilo: en vez de repetirselo a la clienta se le manda
         * el enlace de la agenda y la conversacion pasa a una persona.
         */
        $texto = $delCodigo
            ? $respuesta['text']
            : (app(Repetido::class)->atajar($conversacion, $respuesta['text'], null, $pendientes) ?? $respuesta['text']);

        $dispatcher->queue(
            $conversacion->business,
            Message::KIND_AGENT,
            $conversacion->phone,
            $texto,
            null,
            $conversacion->client,
            null,
            null,
            // Para que la respuesta quede en el hilo y no suelta en el outbox.
            $conversacion,
        );
    }

    /**
     * Todo lo que escribio y aun no se le ha contestado, junto.
     *
     * Se corta en 1000 caracteres porque es el tope del Core; quien manda
     * mas que eso en un rato no esta agendando una cita.
     */
    private function pendientes(WhatsappConversation $conversacion): string
    {
        /*
         * Por ID y no por fecha: un mensaje y la respuesta pueden caer en
         * el mismo segundo, y entonces "lo posterior a la ultima
         * respuesta" incluiria lo que ya se contesto -- y el modelo
         * volveria a responder algo viejo.
         */
        $ultimaRespuesta = (int) Message::withoutGlobalScope('business')
            ->where('conversation_id', $conversacion->id)
            ->where('direction', Message::DIRECTION_OUT)
            ->max('id');

        $entrantes = Message::withoutGlobalScope('business')
            ->where('conversation_id', $conversacion->id)
            ->where('direction', Message::DIRECTION_IN)
            ->where('id', '>', $ultimaRespuesta)
            ->orderBy('id')
            ->get(['body', 'created_at']);

        /*
         * Solo los pedazos que llegaron CERCA del último -- los de una
         * misma idea ("quiero cita" / "para mañana" / "con Anyi"). Lo que
         * quedó sin responder hace horas no decide la respuesta de ahora:
         * Alejandro escribió "Buenas" y el bot le contestó "¿cómo prefieres
         * agendar?" porque arrastró su "Quiero agendar una cita" de tres
         * horas antes, que había muerto en una pausa. Eso sigue en la
         * bandeja para el equipo; aquí sobra.
         */
        $ultimo = $entrantes->last()?->created_at;
        $textos = $entrantes
            ->filter(fn (Message $m) => $ultimo === null
                || $m->created_at === null
                || $m->created_at->gte($ultimo->copy()->subMinutes(self::PEDAZOS_MINUTOS)))
            ->pluck('body')
            ->filter()
            ->all();

        return mb_substr(implode("\n", $textos), -1000);
    }

    private function ultimoEntranteId(WhatsappConversation $conversacion): int
    {
        return (int) Message::withoutGlobalScope('business')
            ->where('conversation_id', $conversacion->id)
            ->where('direction', Message::DIRECTION_IN)
            ->max('id');
    }
}
