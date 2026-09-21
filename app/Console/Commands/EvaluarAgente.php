<?php

namespace App\Console\Commands;

use App\Ai\EsUnaPrueba;
use App\Ai\OpcionesEnviadas;
use App\Models\Business;
use App\Services\Ia\Evaluacion\Banco;
use App\Services\Ia\Evaluacion\CasosReales;
use App\Services\Ia\IaCoreClient;
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
        return Banco::telefono($this->option('telefono'));
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
        $banco = new Banco($business);
        $sesion = $banco->preparar($this->telefono(), (bool) ($caso['con_cita'] ?? false));

        $respuestas = [];

        try {
            /*
             * Los mensajes van JUNTOS, como los junta el debounce en
             * producción: evaluar pedazo por pedazo mediría algo que no pasa
             * en la vida real.
             */
            $respuesta = $ia->ask($sesion->conversacion, implode('
', $caso['mensajes']));
        } finally {
            $banco->limpiar($sesion);
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
}
