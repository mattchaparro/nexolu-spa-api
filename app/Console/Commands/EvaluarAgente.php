<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\WhatsappConversation;
use App\Services\Ia\Evaluacion\CasosReales;
use App\Services\Ia\IaCoreClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
 */
class EvaluarAgente extends Command
{
    protected $signature = 'ia:evaluar
                            {--business=1 : Negocio contra el que se evalúa}
                            {--caso= : Solo los casos cuyo nombre contenga esto}
                            {--ver-respuestas : Muestra lo que contestó, no solo el veredicto}';

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

        $this->info("Evaluando {$casos->count()} casos contra {$business->name}…");
        $this->newLine();

        $fallas = 0;
        $mejorables = 0;

        foreach ($casos as $caso) {
            /*
             * Cada caso corre en una transacción que se revierte: la
             * evaluación NO puede dejar citas de mentira en la agenda del
             * local ni fichas de clientas inventadas.
             */
            DB::beginTransaction();

            try {
                [$problemas, $avisos, $respuestas] = $this->correr($ia, $business, $caso);
            } finally {
                DB::rollBack();
            }

            if ($problemas !== []) {
                $fallas++;
                $this->line("  <fg=red>✗</> {$caso['nombre']}");
            } elseif ($avisos !== []) {
                $mejorables++;
                $this->line("  <fg=yellow>~</> {$caso['nombre']}");
            } else {
                $this->line("  <fg=green>✓</> {$caso['nombre']}");
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

        $this->newLine();
        $total = $casos->count();

        $bien = $total - $fallas - $mejorables;
        $this->line("  <fg=green>{$bien} bien</>  <fg=yellow>{$mejorables} mejorables</>  <fg=red>{$fallas} inaceptables</>  de {$total}");

        // Solo lo inaceptable tumba la evaluación: si "mejorable" fallara,
        // nadie la correría.
        return $fallas === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} fallas, avisos, respuestas
     */
    private function correr(IaCoreClient $ia, Business $business, array $caso): array
    {
        $telefono = '57300'.random_int(1000000, 9999999);

        $cliente = Client::create([
            'business_id' => $business->id,
            'name' => 'Evaluación',
            'phone' => $telefono,
            'is_active' => true,
        ]);

        $conversacion = WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $business->id,
            'phone' => $telefono,
            'client_id' => $cliente->id,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        if ($caso['con_cita'] ?? false) {
            $this->citaDePrueba($business, $cliente);
        }

        $respuestas = [];

        /*
         * Los mensajes van JUNTOS, como los junta el debounce en
         * producción: evaluar pedazo por pedazo mediría algo que no pasa
         * en la vida real.
         */
        $respuesta = $ia->ask($conversacion, implode("\n", $caso['mensajes']));

        if ($respuesta === null) {
            return [false, ['el Core no respondió (¿modelo configurado?)'], []];
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

        // Lo esperado es un AVISO: no está roto, está mediocre -- y esta
        // lista es justo lo próximo a mejorar.
        foreach ($caso['espera'] as $herramienta) {
            if (! in_array($herramienta, $usadas, true)) {
                $avisos[] = "no llamó `{$herramienta}` (llamó: ".(implode(', ', $usadas) ?: 'nada').')';
            }
        }

        return [$fallas, $avisos, $respuestas];
    }

    /** Una cita próxima, para los casos de cancelar/mover/consultar. */
    private function citaDePrueba(Business $business, Client $cliente): void
    {
        $servicio = $business->services()->where('is_active', true)->first();
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
