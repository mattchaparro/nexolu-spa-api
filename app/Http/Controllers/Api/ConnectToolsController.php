<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiArgumentException;
use App\Ai\Resolves;
use App\Models\Business;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que un FLUJO de Connect puede preguntarle al spa.
 *
 * La accion "Solicitud externa" de un flujo pega aca con la llave de
 * `connect.key` y guarda pedazos de la respuesta en los custom fields del
 * contacto (`save: {horarios: "resumen"}`) para mostrarlos en el siguiente
 * mensaje. Por eso el contrato es distinto al de una API normal:
 *
 * - SIEMPRE 200 con un `resumen` legible en espanol: un flujo no sabe
 *   manejar un 422, pero si sabe mostrar "No existe el servicio X". El
 *   error ES contenido.
 * - Reusa AvailabilityService y los mismos resolvedores por nombre del
 *   agente IA: las horas que promete un flujo son las mismas que promete
 *   el bot y la pagina publica.
 *
 * El agendamiento NO vive aca a proposito: agendar es conversacion con
 * confirmacion (el agente IA) o la pagina publica - un flujo de botones no
 * tiene como confirmar nombre/hora sin volverse el arbol de 140 nodos que
 * estamos matando.
 */
class ConnectToolsController
{
    use Resolves;

    public function __construct(private readonly AvailabilityService $availability) {}

    public function disponibilidad(Request $request): JsonResponse
    {
        $business = $this->resolveBusiness((string) $request->query('negocio', ''));

        if ($business === null) {
            return response()->json([
                'hay' => false,
                'horas' => [],
                'resumen' => 'Negocio no encontrado: revisa el parámetro «negocio» del flujo.',
            ]);
        }

        $tz = $business->businessTimezone();
        $fecha = $this->resolveDate((string) $request->query('fecha', ''), $tz);

        if ($fecha === null) {
            return response()->json([
                'hay' => false,
                'horas' => [],
                'resumen' => 'Fecha inválida: usa AAAA-MM-DD (o «hoy» / «mañana»).',
            ]);
        }

        try {
            $servicio = $this->resolveService($business->id, (string) $request->query('servicio', ''));
            $sede = $this->resolveLocation($business->id, $request->query('sede'));

            $empleado = $request->filled('empleado')
                ? $this->resolveResource($business->id, (string) $request->query('empleado'), $sede?->id)
                : null;
        } catch (AiArgumentException $e) {
            // El mensaje del resolvedor ya esta escrito para una clienta
            // ("No existe el servicio X. Los que hay: ..."): va tal cual.
            return response()->json(['hay' => false, 'horas' => [], 'resumen' => $e->getMessage()]);
        }

        $slots = $this->availability->slotsForService(
            $business,
            $servicio,
            $fecha,
            $empleado,
            null,
            $sede?->id,
        );

        $horas = collect($slots)
            ->map(fn (array $s) => $s['starts_at']->setTimezone($tz)->format('H:i'))
            ->unique()
            ->values();

        if ($horas->isEmpty()) {
            return response()->json([
                'hay' => false,
                'servicio' => $servicio->name,
                'fecha' => $fecha->format('Y-m-d'),
                'horas' => [],
                'resumen' => sprintf(
                    'No quedan horas libres para %s el %s.',
                    $servicio->name,
                    $fecha->format('Y-m-d'),
                ),
            ]);
        }

        /*
         * Un mensaje de WhatsApp no es una tabla: 6 horas repartidas
         * alcanzan para elegir, y "y" antes de la ultima se lee como lo
         * escribiria una persona.
         */
        $muestra = $horas->take(6);
        $resumen = $muestra->count() === 1
            ? $muestra->first()
            : $muestra->slice(0, -1)->implode(', ').' y '.$muestra->last();

        return response()->json([
            'hay' => true,
            'servicio' => $servicio->name,
            'fecha' => $fecha->format('Y-m-d'),
            'horas' => $horas->all(),
            'resumen' => $resumen,
            'hay_mas' => $horas->count() > 6,
        ]);
    }

    private function resolveBusiness(string $negocio): ?Business
    {
        if ($negocio === '') {
            return null;
        }

        if (ctype_digit($negocio)) {
            return Business::find((int) $negocio);
        }

        return Business::where('slug', $negocio)->first();
    }

    /**
     * La fecha como la escribe un flujo: fija (AAAA-MM-DD) o relativa
     * («hoy», «mañana») - un flujo no sabe calcular fechas, este endpoint
     * si. Vacia = mañana, el caso tipico de "¿tienen agenda?".
     */
    private function resolveDate(string $fecha, string $tz): ?CarbonImmutable
    {
        $hoy = CarbonImmutable::now($tz)->startOfDay();

        return match (mb_strtolower(trim($fecha))) {
            '', 'manana', 'mañana' => $hoy->addDay(),
            'hoy' => $hoy,
            'pasado manana', 'pasado mañana' => $hoy->addDays(2),
            default => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($fecha)) === 1
                ? CarbonImmutable::createFromFormat('!Y-m-d', trim($fecha), $tz)
                : null,
        };
    }
}
