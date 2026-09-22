<?php

namespace App\Console\Commands;

use App\Ai\EsUnaPrueba;
use App\Ai\NombreRaro;
use App\Ai\Repetido;
use App\Ai\Toques;
use App\Models\Business;
use App\Models\Resource;
use App\Models\Service;
use App\Services\Ia\Evaluacion\Banco;
use App\Services\Ia\Evaluacion\Perfiles;
use App\Services\Ia\IaCoreClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Clientas simuladas que conversan con el bot hasta agendar o irse.
 *
 * `ia:evaluar` mide un turno. Las conversaciones reales se rompen ENTRE
 * turnos: la lista que se traga el segundo servicio, la pregunta que se
 * repite, el "?" que recibe otra cosa, la preferencia por una profesional
 * que se ignora. Ningún caso de un turno ve eso, y el dueño no puede
 * sentarse a interpretar a todas sus clientas.
 *
 * Acá el modelo interpreta a una persona concreta -- una abuela, alguien
 * con afán, alguien que prefiere la página -- con una meta, y habla con el
 * bot DE VERDAD (el mismo Core, las mismas herramientas, el catálogo real)
 * hasta conseguirla o rendirse. Lo que se mide es lo que le importa al
 * negocio: si agendó, si agendó LO QUE QUERÍA, en cuántos mensajes, y qué
 * cosas dijo el bot que no debía.
 *
 * Como la evaluación, gasta tokens y conversa con el número de
 * `IA_EVAL_PHONE` sin que salga nada. Cada corrida es distinta a
 * propósito -- la clienta simulada va a temperatura alta -- así que un
 * perfil que falla una vez es una pista, y uno que falla tres es un bug.
 */
class SimularClientas extends Command
{
    protected $signature = 'ia:simular
                            {--business=1 : Negocio contra el que se simula}
                            {--perfil= : Solo este perfil (clave)}
                            {--veces=1 : Cuántas conversaciones por perfil}
                            {--telefono= : Con qué número conversa (por defecto, services.ia_eval.phone)}
                            {--guardar : Escribe cada transcripción en storage/app/simulaciones}';

    protected $description = 'Clientas simuladas conversan con el bot de punta a punta y se reporta qué pasó';

    private const MARCA_LISTO = '[LISTO]';

    private const MARCA_ME_VOY = '[ME VOY]';

    public function handle(IaCoreClient $ia): int
    {
        $business = Business::find((int) $this->option('business'));

        if ($business === null) {
            $this->error('No existe ese negocio.');

            return self::FAILURE;
        }

        $perfiles = collect(Perfiles::todos())
            ->when($this->option('perfil'), fn ($c, $clave) => $c->where('clave', $clave)->values());

        if ($perfiles->isEmpty()) {
            $this->error('No hay ningún perfil con esa clave.');

            return self::FAILURE;
        }

        $telefono = Banco::telefono($this->option('telefono'));
        $veces = max(1, (int) $this->option('veces'));

        $this->info("Simulando {$perfiles->count()} perfiles × {$veces} contra {$business->name} (como {$telefono}; nada sale)…");
        $this->newLine();

        $banco = new Banco($business);
        $resumen = [];

        try {
            foreach ($perfiles as $perfil) {
                for ($i = 1; $i <= $veces; $i++) {
                    EsUnaPrueba::marcar($telefono);
                    $resultado = $this->conversar($ia, $business, $banco, $perfil, $telefono);
                    $resumen[] = $resultado;
                    $this->imprimir($perfil, $i, $resultado);

                    if ($this->option('guardar')) {
                        $this->guardar($perfil, $i, $resultado);
                    }
                }
            }
        } finally {
            EsUnaPrueba::olvidar($telefono);
        }

        $this->newLine();
        $logradas = count(array_filter($resumen, fn ($r) => $r['logrado']));
        $this->line("  <fg=green>{$logradas} lograron su meta</>  de ".count($resumen).' conversaciones');

        return self::SUCCESS;
    }

    /**
     * Una conversación completa: la clienta simulada y el bot, por turnos.
     *
     * @param  array<string, mixed>  $perfil
     * @return array<string, mixed>
     */
    private function conversar(IaCoreClient $ia, Business $business, Banco $banco, array $perfil, string $telefono): array
    {
        $sesion = $banco->preparar($telefono, (bool) ($perfil['con_cita'] ?? false), $perfil['nombre_propio'] ?? null);
        $catalogo = $this->catalogo($business);

        $transcripcion = [];
        $hallazgos = [];
        $herramientas = [];
        $desenlace = 'se agotaron los turnos';
        $turnos = 0;

        try {
            $mensaje = $this->clienta($ia, $business, $perfil, $transcripcion);

            for ($turno = 1; $turno <= (int) ($perfil['maximo_turnos'] ?? 10); $turno++) {
                if ($mensaje === null) {
                    $desenlace = 'la clienta simulada no respondió (Core)';
                    break;
                }

                if (str_contains($mensaje, self::MARCA_LISTO)) {
                    $desenlace = 'la clienta dio por logrado su objetivo';
                    break;
                }

                if (str_contains($mensaje, self::MARCA_ME_VOY)) {
                    $desenlace = 'la clienta se fue';
                    break;
                }

                $turnos++;
                $mensaje = $this->comoLlegaria($mensaje, $transcripcion);
                $transcripcion[] = ['quien' => 'clienta', 'texto' => $mensaje];

                EsUnaPrueba::marcar($telefono);

                /*
                 * Igual que en produccion: si `hablar_con_persona` pauso al
                 * bot, el job NO le pregunta al Core (ver AnswerWhatsappMessageJob).
                 * Sin esto la simulacion mostraba al bot repitiendo "ya avise
                 * a alguien" cinco veces, cosa que en la vida real no pasa:
                 * ahi el bot se calla y contesta una persona.
                 */
                $sesion->conversacion->refresh();

                if ($sesion->conversacion->agentIsPaused()) {
                    $transcripcion[] = ['quien' => 'bot', 'texto' => '(el bot está en pausa: la atiende una persona)', 'opciones' => [], 'herramientas' => []];
                    $herramientas[] = [];
                    $mensaje = $this->clienta($ia, $business, $perfil, $transcripcion);

                    continue;
                }

                // Como el job: los toques los atiende el codigo, el resto el modelo.
                $respuesta = app(Toques::class)->atender($sesion->conversacion, $mensaje)
                    ?? $ia->ask($sesion->conversacion, $mensaje);
                $enviados = EsUnaPrueba::enviados($telefono);

                $opciones = $this->opcionesDe($enviados);
                $textosDeListas = $this->textosDe($enviados);
                /*
                 * Igual que en produccion: si una herramienta ya mando
                 * botones, el job NO manda el texto del modelo (ver
                 * AnswerWhatsappMessageJob y OpcionesEnviadas::consumir).
                 * Mostrarlo aca seria medir un mensaje que la clienta
                 * nunca recibe.
                 */
                $textoBot = $opciones !== [] ? '' : trim((string) ($respuesta['text'] ?? ''));

                /*
                 * Igual que en produccion: si el modelo iba a repetirse, se
                 * corta el bucle -- enlace de la agenda y pasa a una persona
                 * (ver Repetido y AnswerWhatsappMessageJob). El transcript
                 * hace de hilo porque aca el modelo no persiste mensajes.
                 */
                if ($textoBot !== '') {
                    $previos = collect($transcripcion)
                        ->where('quien', 'bot')
                        ->pluck('texto')
                        ->filter()
                        ->values()
                        ->all();
                    $eco = app(Repetido::class)->atajar($sesion->conversacion, $textoBot, $previos);

                    if ($eco !== null) {
                        $hallazgos[] = "turno {$turno}: iba a repetirse; se cortó el bucle (enlace + persona)";
                        $textoBot = $eco;
                    }
                }

                $usadas = $respuesta['tools_used'] ?? [];
                $herramientas[] = $usadas;

                if ($respuesta === null && $enviados === []) {
                    $transcripcion[] = ['quien' => 'bot', 'texto' => '(sin respuesta)', 'opciones' => []];
                    $hallazgos[] = "turno {$turno}: el bot no respondió nada";
                    $mensaje = $this->clienta($ia, $business, $perfil, $transcripcion);

                    continue;
                }

                $visible = trim(implode("\n", array_filter([...$textosDeListas, $textoBot])));
                $transcripcion[] = ['quien' => 'bot', 'texto' => $visible, 'opciones' => $opciones, 'herramientas' => $usadas];

                foreach ($this->revisar($visible, $opciones, $transcripcion, $catalogo) as $h) {
                    $hallazgos[] = "turno {$turno}: {$h}";
                }

                $mensaje = $this->clienta($ia, $business, $perfil, $transcripcion);
            }

            $citas = $banco->citasCreadas($sesion)->map(fn ($c) => [
                'cuando' => $c->starts_at->timezone($business->businessTimezone())->format('D d M g:i a'),
                'servicios' => $c->items->map(fn ($i) => $i->service?->name)->filter()->values()->all(),
                'con' => $c->items->map(fn ($i) => $i->resource?->name)->filter()->unique()->values()->all(),
            ])->all();

            /*
             * Quien vino a MOVER su cita no puede terminar con dos. La
             * clienta simulada quedo feliz ("mi cita quedo en la tarde")
             * sin saber que la de las 10 am seguia viva: el salon la iba
             * a esperar dos veces. La meta lograda no tapa este dato.
             */
            if (($perfil['con_cita'] ?? false) && count($citas) > 1) {
                $hallazgos[] = 'vino a mover UNA cita y terminó con '.count($citas).' (la original no se movió)';
            }
        } finally {
            $banco->limpiar($sesion);
        }

        $agendados = collect($citas)->flatMap(fn ($c) => $c['servicios'])->sort()->values()->all();
        $esperados = $perfil['servicios_esperados'];

        $logrado = $desenlace === 'la clienta dio por logrado su objetivo';
        $correcto = $esperados === null
            ? null
            : ($agendados === collect($esperados)->sort()->values()->all());

        if ($correcto === false) {
            $hallazgos[] = 'quedó agendado ['.implode(', ', $agendados).'] y se esperaba ['.implode(', ', $esperados).']';
        }

        return [
            'perfil' => $perfil,
            'transcripcion' => $transcripcion,
            'turnos' => $turnos,
            'desenlace' => $desenlace,
            'logrado' => $logrado,
            'correcto' => $correcto,
            'citas' => $citas,
            'hallazgos' => $hallazgos,
            'herramientas' => $herramientas,
        ];
    }

    /**
     * El siguiente mensaje de la clienta simulada.
     *
     * @param  array<string, mixed>  $perfil
     * @param  list<array<string, mixed>>  $transcripcion
     */
    private function clienta(IaCoreClient $ia, Business $business, array $perfil, array $transcripcion): ?string
    {
        $system = 'Estás en una simulación de prueba de un chatbot. Interpretas a una clienta que le '
            ."escribe por WhatsApp a un salón de uñas llamado {$business->name}.\n\n"
            ."QUIÉN ERES: {$perfil['persona']}\n\n"
            ."TU META: {$perfil['meta']}\n\n"
            ."REGLAS:\n"
            ."- Responde ÚNICAMENTE con el texto de tu siguiente mensaje de WhatsApp. Sin comillas, sin explicaciones, sin narrar.\n"
            ."- Escribe exactamente como escribiría esa persona (largo, ortografía, tono).\n"
            ."- Si el salón te muestra OPCIONES (botones o una lista) y una te sirve, respóndela con su texto EXACTO, tal cual aparece.\n"
            ."- Solo puedes elegir servicios que el salón te ofrezca; no inventes nombres.\n"
            .'- Si YA lograste tu meta, responde exactamente '.self::MARCA_LISTO.'. Una cita está lograda SOLO cuando '
            ."te dicen que QUEDÓ agendada; que te pregunten \"¿lo agendo?\" no es haberla logrado: ahí contestas.\n"
            .'- Si te frustras, te ignoran lo que pediste dos veces, o llevas muchos mensajes sin avanzar, responde exactamente '.self::MARCA_ME_VOY.".\n"
            .'- Nunca digas que eres una simulación ni una IA.';

        $lineas = [];

        foreach ($transcripcion as $t) {
            if ($t['quien'] === 'clienta') {
                $lineas[] = 'TÚ: '.$t['texto'];

                continue;
            }

            $linea = 'SALÓN: '.$t['texto'];

            if (! empty($t['opciones'])) {
                $linea .= "\n   BOTONES (para tocar uno, responde SOLO su texto exacto): "
                    .implode(' | ', array_column($t['opciones'], 'title'));
                $detalles = array_filter(array_map(
                    fn (array $o) => isset($o['description']) ? "{$o['title']}: {$o['description']}" : null,
                    $t['opciones'],
                ));
                if ($detalles !== []) {
                    $linea .= "\n   (debajo de cada botón se lee: ".implode(' · ', $detalles).')';
                }
            }

            $lineas[] = $linea;
        }

        $user = $lineas === []
            ? 'Todavía no has escrito nada. Escribe tu PRIMER mensaje al salón.'
            : "Conversación hasta ahora:\n\n".implode("\n\n", $lineas)."\n\nEscribe tu siguiente mensaje.";

        $texto = $ia->completar($business, $system, $user, 0.9);

        return $texto === null ? null : trim($texto, " \t\n\"'");
    }

    /**
     * Lo que el bot dijo que no debía, medido sobre lo que la clienta ve.
     *
     * Son las fallas que ya pasaron con gente de verdad. Ninguna mira si
     * la redacción es bonita: miran cosas concretas que se pueden contar.
     *
     * @param  list<array{title: string, description?: string}>  $opciones
     * @param  list<array<string, mixed>>  $transcripcion
     * @param  array{servicios: list<string>, otros: list<string>}  $catalogo
     * @return list<string>
     */
    private function revisar(string $texto, array $opciones, array $transcripcion, array $catalogo): array
    {
        $hallazgos = [];
        $plano = mb_strtolower($texto);

        // La pregunta que obliga a adivinar: la clienta no sabe qué horas hay.
        if (preg_match('/a qu[eé] hora (te|le) (gustar|sirve|queda|viene|conviene)|qu[eé] hora (te|le) (gustar|sirve|queda)/u', $plano)) {
            $hallazgos[] = 'preguntó "¿a qué hora te gustaría?" en vez de ofrecer horas';
        }

        // Horas en formato de máquina.
        if (preg_match('/\b([01]?\d|2[0-3]):[0-5]\d\b(?!\s*(am|pm|a\.\s?m|p\.\s?m))/iu', $texto)) {
            $hallazgos[] = 'escribió una hora en formato 24h';
        }

        // La misma respuesta dos veces seguidas: se lee como que no entendió.
        $anteriores = array_values(array_filter($transcripcion, fn ($t) => $t['quien'] === 'bot'));
        array_pop($anteriores); // la de este turno ya esta en la transcripcion

        foreach ($anteriores as $previa) {
            if ($texto !== '' && $this->plano($texto) === $this->plano($previa['texto'] ?? '')) {
                $hallazgos[] = 'repitió una respuesta que ya había dado';
                break;
            }
        }

        // Una lista de horas o servicios escrita a mano, en vez de botones.
        if (preg_match_all('/^\s*(\*\s+|-\s+|•\s+|⏰|💅)/mu', $texto) >= 4 && $opciones === []) {
            $hallazgos[] = 'escribió una lista larga como texto en vez de mandar botones';
        }

        // Nombres de servicio que no existen en el catálogo.
        if (preg_match_all('/\*([^*\n]{3,60})\*/u', $texto, $m)) {
            foreach ($m[1] as $negrilla) {
                // "Tradicional y Semipermanente Hombre" son dos nombres
                // reales unidos: se mira cada pedazo.
                foreach (preg_split('/\s+y\s+/u', $negrilla) ?: [] as $pedazo) {
                    $n = mb_strtolower(trim($pedazo));
                    $pareceServicio = preg_match('/manicur|pedicur|semi|u[nñ]as|acr[ií]l|rubber|capping|retoque|retiro|pesta|ceja|tradicional/u', $n);
                    // Existe si es un nombre del catalogo o un pedazo de uno
                    // ("Semi" de "Semi + Rubber"). NO al reves: "Semipermanente
                    // con Rubber" contiene "Semipermanente" y aun asi no existe.
                    $existe = collect($catalogo['servicios'])->contains(fn ($s) => $n === mb_strtolower($s) || str_contains(mb_strtolower($s), $n));
                    $esOtraCosa = collect($catalogo['otros'])->contains(fn ($o) => str_contains($n, mb_strtolower($o)));

                    if ($pareceServicio && ! $existe && ! $esOtraCosa) {
                        $hallazgos[] = "nombró un servicio que no existe: «{$pedazo}»";
                    }
                }
            }
        }

        // Sede única nombrada como si hubiera varias.
        if (str_contains($plano, 'sede principal')) {
            $hallazgos[] = 'nombró "la sede Principal" habiendo una sola sede';
        }

        // Saludar con el nombre del perfil de WhatsApp cuando no parece de
        // persona: "¡Hola, 🦋 Yess 🦋!" delata que nadie preguntó el nombre.
        foreach ($catalogo['nombres_raros'] ?? [] as $raro) {
            if ($raro !== '' && str_contains($texto, $raro)) {
                $hallazgos[] = "saludó con el nombre del perfil («{$raro}») en vez de preguntar el nombre";
            }
        }

        return $hallazgos;
    }

    /**
     * @return array{servicios: list<string>, otros: list<string>}
     */
    private function catalogo(Business $business): array
    {
        return [
            'nombres_raros' => array_values(array_filter(
                collect(Perfiles::todos())->pluck('nombre_propio')->all(),
                fn ($n) => NombreRaro::es($n),
            )),
            'servicios' => Service::withoutGlobalScope('business')->where('business_id', $business->id)->pluck('name')->all(),
            'otros' => [
                ...Resource::withoutGlobalScope('business')->where('business_id', $business->id)->pluck('name')->all(),
                'manicure', 'pedicure', 'pestañas', 'cejas', 'manos y pies', 'manos', 'pies', 'uñas',
                'semipermanente y', 'para 2 personas',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $enviados
     * @return list<array{title: string, description?: string}>
     */
    private function opcionesDe(array $enviados): array
    {
        $filas = [];

        foreach ($enviados as $envio) {
            foreach ($envio['whatsapp_options']['options'] ?? [] as $o) {
                $filas[] = array_filter(['title' => $o['title'], 'description' => $o['description'] ?? null]);
            }
        }

        return $filas;
    }

    /**
     * @param  list<array<string, mixed>>  $enviados
     * @return list<string>
     */
    private function textosDe(array $enviados): array
    {
        return array_values(array_filter(array_map(fn ($e) => trim((string) ($e['text'] ?? '')), $enviados)));
    }

    /**
     * Lo que WhatsApp entregaria si la clienta toco una fila.
     *
     * De una fila tocada solo llega el TITULO. La clienta simulada a veces
     * copia tambien lo que va debajo ("semi + rubber (60 min · 55.000
     * cop)"); en produccion eso no existe, asi que se normaliza al titulo.
     *
     * @param  list<array<string, mixed>>  $transcripcion
     */
    private function comoLlegaria(string $mensaje, array $transcripcion): string
    {
        $ultimoBot = null;

        foreach (array_reverse($transcripcion) as $t) {
            if ($t['quien'] === 'bot') {
                $ultimoBot = $t;
                break;
            }
        }

        foreach ($ultimoBot['opciones'] ?? [] as $o) {
            if (str_starts_with($this->plano($mensaje), $this->plano($o['title']))) {
                return $o['title'];
            }
        }

        return $mensaje;
    }

    private function plano(string $texto): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($texto))) ?? '';
    }

    /** @param array<string, mixed> $perfil */
    private function imprimir(array $perfil, int $corrida, array $r): void
    {
        $icono = $r['logrado'] && $r['correcto'] !== false ? '<fg=green>✓</>' : ($r['logrado'] ? '<fg=yellow>~</>' : '<fg=red>✗</>');
        $this->line("  {$icono} {$perfil['nombre']} <fg=gray>(#{$corrida}, {$r['turnos']} turnos)</> — {$r['desenlace']}");

        foreach ($r['citas'] as $c) {
            $this->line('      <fg=cyan>agendó:</> '.implode(' + ', $c['servicios']).' · '.$c['cuando'].' · con '.implode(' y ', $c['con']));
        }

        foreach ($r['hallazgos'] as $h) {
            $this->line("      <fg=yellow>{$h}</>");
        }

        foreach ($r['transcripcion'] as $t) {
            $quien = $t['quien'] === 'clienta' ? '<fg=magenta>clienta</>' : '<fg=blue>bot    </>';
            $texto = str_replace("\n", ' ⏎ ', $t['texto']);
            $this->line("        {$quien} ".mb_substr($texto, 0, 220));

            if (! empty($t['opciones'])) {
                $this->line('                <fg=gray>['.implode(' | ', array_column($t['opciones'], 'title')).']</>');
            }
        }

        $this->newLine();
    }

    /** @param array<string, mixed> $perfil */
    private function guardar(array $perfil, int $corrida, array $r): void
    {
        $dir = storage_path('app/simulaciones');
        File::ensureDirectoryExists($dir);

        $md = "# {$perfil['nombre']} (#{$corrida})\n\n";
        $md .= "- Desenlace: {$r['desenlace']}\n- Turnos: {$r['turnos']}\n";
        $md .= '- Agendó: '.(empty($r['citas']) ? 'nada' : implode(' | ', array_map(fn ($c) => implode(' + ', $c['servicios']).' · '.$c['cuando'], $r['citas'])))."\n";
        $md .= '- Esperado: '.($perfil['servicios_esperados'] === null ? '(mover la cita)' : (implode(', ', $perfil['servicios_esperados']) ?: 'no agendar'))."\n\n";

        if ($r['hallazgos'] !== []) {
            $md .= "## Hallazgos\n\n".implode("\n", array_map(fn ($h) => "- {$h}", $r['hallazgos']))."\n\n";
        }

        $md .= "## Conversación\n\n";

        foreach ($r['transcripcion'] as $t) {
            $md .= ($t['quien'] === 'clienta' ? '**Clienta:** ' : '**Bot:** ').str_replace("\n", "  \n", $t['texto'])."\n";

            if (! empty($t['opciones'])) {
                $md .= '  _[opciones: '.implode(' | ', array_column($t['opciones'], 'title'))."]_\n";
            }

            if (! empty($t['herramientas'])) {
                $md .= '  _herramientas: '.implode(', ', $t['herramientas'])."_\n";
            }

            $md .= "\n";
        }

        File::put($dir.'/'.now()->format('Ymd-His')."-{$perfil['clave']}-{$corrida}.md", $md);
    }
}
