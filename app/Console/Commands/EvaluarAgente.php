<?php

namespace App\Console\Commands;

use App\Ai\EsUnaPrueba;
use App\Ai\OpcionesEnviadas;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\Ia\Evaluacion\CasosReales;
use App\Services\Ia\IaCoreClient;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Pasa al agente por conversaciones reales y dice dónde falla.
 *
 * Existe porque probar a mano por WhatsApp no escala: cada cambio de
 * prompt puede romper algo que funcionaba y hoy eso se descubre con una
 * clienta de verdad. Acá se descubre antes, en veinte minutos y contra
 * el catálogo del negocio.
 *
 * Lo que verifica NO es qué palabras usa -- exigirle una frase exacta a
 * un modelo es escribir una prueba que falla cuando el bot mejora --
 * sino qué HACE: qué herramientas llamó y cuáles no. Que no agende sin
 * confirmar, que no invente horas, que pase a una persona cuando toca.
 *
 * Gasta tokens de verdad (llama al modelo), así que no corre solo: se
 * corre cuando se toca el prompt o las herramientas.
 *
 * Y NO da el mismo número dos veces: se midió 23, 24, 25 y 26 de 28 sin
 * tocar una línea. Una mejora de menos de tres puntos en una sola pasada
 * no dice nada -- para creerle a un cambio, `--repetir=3` y mirar el
 * (bien/veces) de cada caso, que separa lo roto de lo inestable. Lo que
 * se pueda fijar con una prueba de PHPUnit se fija ahí y no acá.
 */
class EvaluarAgente extends Command
{
    protected $signature = 'ia:evaluar
                            {--business=1 : Negocio contra el que se evalúa}
                            {--caso= : Solo los casos cuyo nombre contenga esto}
                            {--ver-respuestas : Muestra lo que contestó, no solo el veredicto}
                            {--repetir=1 : Cuántas veces corre cada caso (el modelo no es determinista)}
                            {--telefono= : Con qué número conversa (por defecto, el de services.ia_eval.phone)}
                            {--enviar : Deja que los mensajes lleguen de verdad a ese número}';

    protected $description = 'Corre conversaciones reales contra el agente y reporta qué se rompió';

    public function handle(IaCoreClient $ia): int
    {
        $business = Business::find((int) $this->option('business'));

        if ($business === null) {
            $this->error('No existe ese negocio.');

            return self::FAILURE;
        }

        $casos = collect(CasosReales::todos())
            ->when($this->option('caso'), fn ($c, $filtro) => $c->filter(
                fn (array $caso) => str_contains($caso['nombre'], $filtro)
            ));

        $telefono = $this->telefono();

        $this->info("Evaluando {$casos->count()} casos contra {$business->name}…");
        $this->line($this->option('enviar')
            ? "  <fg=yellow>Los mensajes SALEN a {$telefono}.</>"
            : "  <fg=gray>Conversando como {$telefono}; los mensajes no salen (--enviar para verlos llegar).</>");
        $this->newLine();

        /*
         * Salvo que se pida lo contrario, los mensajes NO salen. El bot
         * contesta mandando listas de horas por WhatsApp, y veintiocho
         * conversaciones seguidas llenarían el teléfono de quien está
         * midiendo. Con `--enviar` sí llegan: es como se revisa cómo se
         * VEN, que es distinto de qué hace el bot.
         */
        if (! $this->option('enviar')) {
            EsUnaPrueba::marcar($telefono);
        }

        try {
            [$fallas, $mejorables] = $this->correrTodos($ia, $business, $casos, $telefono);
        } finally {
            // Que una evaluación interrumpida no deje al número mudo.
            EsUnaPrueba::olvidar($telefono);
            OpcionesEnviadas::olvidar($telefono);
        }

        $this->newLine();
        $total = $casos->count();

        $bien = $total - $fallas - $mejorables;
        $this->line("  <fg=green>{$bien} bien</>  <fg=yellow>{$mejorables} mejorables</>  <fg=red>{$fallas} inaceptables</>  de {$total}");

        // Solo lo inaceptable tumba la evaluación: si "mejorable" fallara,
        // nadie la correría.
        return $fallas === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * El número con el que se conversa.
     *
     * Es uno de verdad -- el de quien mantiene esto -- y no uno inventado
     * a propósito: si algún día un mensaje se escapa de la evaluación, que
     * le llegue a él y no a una desconocida que nunca escribió al local.
     */
    private function telefono(): string
    {
        return ltrim(
            (string) ($this->option('telefono') ?: config('services.ia_eval.phone')),
            '+',
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $casos
     * @return array{0: int, 1: int} fallas, mejorables
     */
    private function correrTodos(IaCoreClient $ia, Business $business, $casos, string $telefono): array
    {
        $fallas = 0;
        $mejorables = 0;

        foreach ($casos as $caso) {
            // La marca de "ya le mandé las horas" dura tres minutos: sin
            // borrarla, el segundo caso hereda la del primero y el bot se
            // queda callado creyendo que ya contestó.
            OpcionesEnviadas::olvidar($telefono);

            // Y la de "esto es una prueba" se renueva, para que dure lo
            // que dure la corrida sin dejar el número mudo si se corta.
            if (! $this->option('enviar')) {
                EsUnaPrueba::marcar($telefono);
            }

            /*
             * El mismo caso, varias veces. El modelo no es determinista:
             * dos corridas seguidas del MISMO código dieron 23 y 26 de
             * 28. Con una sola pasada no se distingue un caso roto de uno
             * inestable, y ahí es donde uno "arregla" algo que no estaba
             * dañado y daña otra cosa.
             */
            $veces = max(1, (int) $this->option('repetir'));
            $problemas = [];
            $avisos = [];
            $respuestas = [];
            $bien = 0;

            for ($i = 0; $i < $veces; $i++) {
                OpcionesEnviadas::olvidar($telefono);

                [$p, $a, $r] = $this->correr($ia, $business, $caso);

                // Se guarda lo PEOR que pasó, que es lo que le va a pasar
                // a alguna clienta, y se cuenta cuántas veces salió bien.
                $problemas = [...$problemas, ...$p];
                $avisos = [...$avisos, ...$a];
                $respuestas = [...$respuestas, ...$r];

                if ($p === [] && $a === []) {
                    $bien++;
                }
            }

            $problemas = array_values(array_unique($problemas));
            $avisos = array_values(array_unique($avisos));
            $deCuantas = $veces > 1 ? " <fg=gray>({$bien}/{$veces})</>" : '';

            if ($problemas !== []) {
                $fallas++;
                $this->line("  <fg=red>✗</> {$caso['nombre']}{$deCuantas}");
            } elseif ($avisos !== []) {
                $mejorables++;
                $this->line("  <fg=yellow>~</> {$caso['nombre']}{$deCuantas}");
            } else {
                $this->line("  <fg=green>✓</> {$caso['nombre']}{$deCuantas}");
            }

            foreach ([...$problemas, ...$avisos] as $linea) {
                $this->line("      <fg=yellow>{$linea}</>");
            }

            if ($problemas !== [] || $avisos !== []) {
                $this->line("      <fg=gray>{$caso['nota']}</>");
            }

            if ($this->option('ver-respuestas')) {
                foreach ($respuestas as $r) {
                    $this->line('      <fg=gray>'.str_replace("\n", ' ⏎ ', mb_substr($r, 0, 160)).'</>');
                }
            }
        }

        return [$fallas, $mejorables];
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} fallas, avisos, respuestas
     */
    private function correr(IaCoreClient $ia, Business $business, array $caso): array
    {
        $telefono = $this->telefono();

        /*
         * Un segundo antes, no `now()`.
         *
         * `now()` trae microsegundos y `created_at` se guarda al segundo:
         * una cita creada en ESTE mismo segundo queda con un `created_at`
         * ANTERIOR a la marca y la limpieza no la veia. Pasaron treinta y
         * nueve citas de prueba a la agenda del salon antes de que se
         * notara.
         */
        $desde = now()->subSecond();

        /*
         * El número es de verdad, así que puede tener ficha de verdad. Se
         * reusa en vez de crear otra: dos fichas con el mismo teléfono es
         * exactamente el enredo que hace que el bot no encuentre las citas
         * de quien le escribe.
         */
        $cliente = Client::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('phone', $telefono)
            ->first()
            ?? Client::create([
                'business_id' => $business->id,
                'name' => 'Evaluación',
                'phone' => $telefono,
                'is_active' => true,
            ]);

        /*
         * La conversación también puede existir de verdad -- hay un índice
         * único por negocio y teléfono -- así que se reusa. Lo que NO se
         * reusa es el hilo del Core: se arranca uno nuevo en cada caso,
         * porque si no, las veintiocho clientas inventadas quedan pegadas
         * a la memoria de la charla real y el bot se acuerda de ellas la
         * próxima vez que escriba una persona.
         */
        $conversacion = WhatsappConversation::withoutGlobalScope('business')
            ->firstOrNew([
                'business_id' => $business->id,
                'phone' => $telefono,
            ]);

        // Cómo estaba, para devolverla igual: es la conversación real de
        // alguien, no un sobrante de prueba.
        $comoEstaba = $conversacion->exists
            ? $conversacion->only([
                'client_id', 'ia_conversation_id', 'last_message_at', 'last_inbound_at',
                'status', 'read_at', 'agent_paused_until',
            ])
            : null;

        /*
         * Y NADA de esto va dentro de una transacción abierta.
         *
         * Parecía lo prudente -- envolver el caso y revertir -- pero quien
         * agenda no es este proceso: la evaluación le habla al Core, el
         * Core le pega al endpoint de herramientas, y ese es otro request
         * con otra conexión. No veía nada de lo que hay acá adentro, así
         * que no protegía de nada... y en cambio dejaba esta fila trancada:
         * `hablar_con_persona` se quedaba 45 segundos esperando el candado
         * y el Core lo daba por caído. La evaluación reportaba que el bot
         * no pasaba un reclamo a una persona, y el bot sí lo pasaba.
         *
         * Se limpia a mano al final, que es lo que de verdad borra lo que
         * el bot haya creado desde el otro lado.
         */
        $conversacion->forceFill([
            'client_id' => $cliente->id,
            'ia_conversation_id' => null,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
        ])->save();

        if ($caso['con_cita'] ?? false) {
            $this->citaDePrueba($business, $cliente);
        }

        $respuestas = [];

        try {
            /*
             * Los mensajes van JUNTOS, como los junta el debounce en
             * producción: evaluar pedazo por pedazo mediría algo que no pasa
             * en la vida real.
             */
            $respuesta = $ia->ask($conversacion, implode("\n", $caso['mensajes']));
        } finally {
            $this->limpiar($business, $cliente, $conversacion, $comoEstaba, $desde);
        }

        if ($respuesta === null) {
            // Lista vacía, no `false`: quien llama las une con `...` y un
            // booleano ahí revienta la corrida entera por un timeout de un
            // solo caso.
            return [[], ['el Core no respondió (¿timeout, límite de uso, modelo sin configurar?)'], []];
        }

        $respuestas[] = $respuesta['text'];
        $usadas = $respuesta['tools_used'] ?? [];

        $fallas = [];
        $avisos = [];

        // Lo prohibido es una FALLA: agendar sin confirmar o tocar la cita
        // de otra persona no es "mejorable", es inaceptable.
        foreach ($caso['prohibido'] as $herramienta) {
            if (in_array($herramienta, $usadas, true)) {
                $fallas[] = "llamó `{$herramienta}` y no debía";
            }
        }

        /*
         * Lo esperado es un AVISO, y basta con UNA de las alternativas:
         * a veces hay dos formas buenas de atender el mismo mensaje
         * (consultar la agenda, u ofrecer primero las variantes del
         * servicio). Exigirlas todas convierte el reporte en ruido y
         * castiga al bot por acertar de otra manera.
         */
        if ($caso['espera'] !== [] && array_intersect($caso['espera'], $usadas) === []) {
            $avisos[] = 'no llamó '.implode(' ni ', array_map(fn ($h) => "`{$h}`", $caso['espera']))
                .' (llamó: '.(implode(', ', $usadas) ?: 'nada').')';
        }

        return [$fallas, $avisos, $respuestas];
    }

    /**
     * Borrar lo que dejó el caso, sin tocar lo que ya estaba.
     *
     * Es la parte que la transacción no hacía. Las citas las crea el bot
     * desde otro proceso, así que se van con un `delete`, no con un
     * rollback -- y se borran por FECHA DE CREACIÓN, no por `source`: la
     * clienta de verdad también agenda por WhatsApp y esas citas son
     * suyas. Borrarle una cita real por limpiar una de prueba es el peor
     * error que puede cometer este comando.
     *
     * @param  array<string, mixed>|null  $comoEstaba  null si la conversación no existía
     */
    private function limpiar(
        Business $business,
        Client $cliente,
        WhatsappConversation $conversacion,
        ?array $comoEstaba,
        CarbonInterface $desde,
    ): void {
        /*
         * Por el SELLO y no solo por la fecha. El teléfono es de verdad y
         * la ficha también: si quien está midiendo agenda una cita suya
         * mientras esto corre, borrarla porque "se creó en los últimos
         * segundos" sería el peor error que puede cometer este comando.
         * Solo se va lo que nació marcado.
         */
        Appointment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('client_id', $cliente->id)
            ->where('created_at', '>=', $desde)
            ->where('notes', 'like', '%'.EsUnaPrueba::SELLO.'%')
            ->get()
            ->each(function (Appointment $cita) {
                // `forceDelete` y no `delete`: un borrado suave deja la
                // fila en la tabla con `deleted_at`, y estas citas no son
                // algo que el local cancelo -- son algo que no debio
                // existir. En una papelera que alguien puede mirar, cada
                // corrida deja treinta mentiras mas.
                $cita->items()->forceDelete();
                $cita->forceDelete();
            });

        Message::withoutGlobalScopes()
            ->where('conversation_id', $conversacion->id)
            ->where('created_at', '>=', $desde)
            ->delete();

        if ($comoEstaba === null) {
            // No existía antes de esta corrida: se va entera.
            $conversacion->delete();

            return;
        }

        /*
         * Con un UPDATE directo y no con `save()`.
         *
         * Quien pausa al bot durante el caso ("pide hablar con alguien")
         * es OTRO proceso -- el que atiende la herramienta --, así que el
         * modelo que tenemos en memoria no se enteró. Para Eloquent, poner
         * de vuelta el valor que ya tenía no es un cambio, y `save()` no
         * escribía nada: la pausa se quedaba en la conversación real y el
         * bot dejaba de contestarle a Alejandro una hora entera. Así se
         * perdió un "Hola, quiero agendar una cita" suyo.
         */
        WhatsappConversation::withoutGlobalScope('business')
            ->whereKey($conversacion->getKey())
            ->update($comoEstaba);
    }

    /** Una cita próxima, para los casos de cancelar/mover/consultar. */
    private function citaDePrueba(Business $business, Client $cliente): void
    {
        /*
         * El primer servicio del catálogo puede no tener a nadie que lo
         * preste: hay que buscar uno que SÍ, o la cita de prueba no se
         * crea y el caso mide otra cosa (pasó: el bot contestaba "no
         * tienes citas" y la evaluación lo daba por bueno).
         */
        $servicio = $business->services()->where('is_active', true)->get()
            ->first(fn ($s) => $s->resources()->exists());
        $recurso = $servicio?->resources()->first();

        if ($servicio === null || $recurso === null) {
            return;
        }

        $cita = Appointment::create([
            'business_id' => $business->id,
            'location_id' => $business->primaryLocation()?->id,
            'client_id' => $cliente->id,
            'client_name' => $cliente->name,
            'client_phone' => $cliente->phone,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_WHATSAPP_AGENT,
            // Marcada igual que las que crea el bot durante la evaluación:
            // es lo que la limpieza busca para borrarla.
            'notes' => EsUnaPrueba::SELLO,
        ]);

        $cita->items()->create([
            'business_id' => $business->id,
            'service_id' => $servicio->id,
            'resource_id' => $recurso->id,
            'starts_at' => $cita->starts_at,
            'ends_at' => $cita->ends_at,
            // Con buffers, lo que ocupa al recurso y lo que ve la clienta
            // no son lo mismo; acá no hay buffers, así que coinciden.
            'service_starts_at' => $cita->starts_at,
            'service_ends_at' => $cita->ends_at,
            'price' => $servicio->price,
            'sort_order' => 0,
        ]);
    }
}
