<?php

namespace App\Services\WhatsApp;

use App\Ai\LoQueMasPiden;
use App\Models\Business;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Ia\BusinessProfile;
use App\Support\TituloCorto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El menu de servicios de WhatsApp, GENERADO desde el catalogo.
 *
 * La razon de existir: el bot estaba adivinando. La clienta decia
 * "pedicure" y el catalogo tiene cuatro; decia "semipermanente" y hay
 * tres. Cada vez que el modelo elige entre variantes que solo el negocio
 * conoce, se equivoca -- y taparlo con reglas de prompt es una escalera
 * infinita.
 *
 * Lo que es una lista finita y conocida se toca, no se adivina: la
 * clienta elige *Semi + Rubber* de un boton y el bot recibe el nombre
 * EXACTO escrito por ella. El lenguaje abierto ("mañana en la tarde",
 * "es para mi mama") se lo queda el bot, que es donde de verdad sirve.
 *
 * Y se genera, no se mantiene a mano: un menu escrito una vez ofrece,
 * seis meses despues, servicios que ya no se prestan. Agregar un
 * servicio en el spa y que el menu lo sepa es la unica forma de que esto
 * no se vuelva mantenimiento doble.
 */
class MenuDeServicios
{
    /** Tope de Meta para las filas de una lista. */
    private const MAX_FILAS = 10;

    /** Tope de Meta para el titulo de una fila. */
    private const MAX_TITULO = 24;

    public function definicion(Business $business): array
    {
        $categorias = $this->categoriasConServicios($business);

        if ($categorias->isEmpty()) {
            return [];
        }

        $nodos = [];
        $filas = [];

        foreach ($categorias as $categoria) {
            $id = 'cat_'.$categoria['id'];
            $filas[] = array_filter([
                'id' => $id,
                'title' => TituloCorto::de($categoria['nombre'], self::MAX_TITULO),
                'description' => $this->resumen($categoria['servicios']),
                'next' => $id,
            ]);

            $nodos[$id] = $this->nodoDeCategoria($categoria);
            foreach ($categoria['servicios'] as $servicio) {
                $nodos['srv_'.$servicio['id']] = $this->nodoDeServicio($servicio);
            }
        }

        /*
         * Las dos filas que no salen del catalogo y siempre hacen falta:
         * quien ya sabe que quiere no deberia navegar un menu, y quien
         * tiene un problema no deberia hablarle a un bot.
         */
        if (count($filas) < self::MAX_FILAS) {
            $filas[] = ['id' => 'agendar', 'title' => 'Ya sé qué quiero', 'next' => 'agendar'];
            $nodos['agendar'] = [
                'type' => 'message',
                'text' => '¡Perfecto! Cuéntame qué servicio quieres y para qué día, '
                    .'y te busco las horas libres 😊',
            ];
        }

        if (count($filas) < self::MAX_FILAS) {
            $filas[] = ['id' => 'humano', 'title' => 'Hablar con alguien', 'next' => 'humano'];
            $nodos['humano'] = [
                'type' => 'blocks',
                'blocks' => [[
                    'type' => 'text',
                    'text' => '¡Claro! Ya le avisé al equipo: en un momento te escriben 🙌',
                ]],
                'next' => 'aviso_humano',
            ];
            $nodos['aviso_humano'] = [
                'type' => 'actions',
                'actions' => [
                    ['type' => 'add_tags', 'tags' => ['pidio_humano']],
                    ['type' => 'notify_app', 'message' => 'La clienta pidió hablar con una persona desde el menú.'],
                ],
            ];
        }

        /*
         * El aviso del momento va ARRIBA del menú: "Alejandra no estará el
         * jueves" es justo lo que hay que saber antes de elegir, no después
         * de haber elegido.
         */
        $aviso = BusinessProfile::comunicado($business);

        $nodos['categorias'] = [
            'type' => 'list',
            'text' => ($aviso === null ? '' : "📣 *{$aviso}*\n\n")
                ."¡Hola! 💅 Esto es lo que hacemos en {$business->name}. "
                .'Elige lo que te interese y seguimos:',
            'button' => 'Ver servicios',
            'rows' => array_slice($filas, 0, self::MAX_FILAS),
        ];

        return ['start' => 'categorias', 'nodes' => $nodos];
    }

    /**
     * Publica el menu en Connect. @return si quedo publicado.
     */
    public function publicar(Business $business): bool
    {
        $definicion = $this->definicion($business);

        if ($definicion === []) {
            return false;
        }

        try {
            $response = Http::withToken((string) config('services.comms_core.api_key'))
                ->baseUrl(rtrim((string) config('services.comms_core.base_url'), '/'))
                ->timeout(20)
                ->put('/v1/flows/menu_servicios', [
                    'business_id' => (string) $business->id,
                    'trigger_type' => 'keyword',
                    'trigger_keywords' => [
                        'menu', 'menú', 'servicios', 'precios', 'informacion',
                        'información', 'info', 'catalogo', 'catálogo',
                    ],
                    'definition' => $definicion,
                    'is_active' => true,
                ]);
        } catch (\Throwable $e) {
            Log::warning('menu_servicios: sin conexión con Connect', ['error' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('menu_servicios: Connect lo rechazó', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Una categoria: su lista de servicios con precio.
     *
     * Si tiene mas de diez, entran los diez primeros por orden del
     * catalogo: una lista mas larga no la deja mandar Meta, y tampoco la
     * leeria nadie.
     */
    private function nodoDeCategoria(array $categoria): array
    {
        return [
            'type' => 'list',
            'text' => "*{$categoria['nombre']}* — elige el que quieras y te digo cuándo hay 👇",
            'button' => 'Ver opciones',
            'rows' => array_map(fn (array $s) => [
                'id' => 'srv_'.$s['id'],
                'title' => TituloCorto::de($s['nombre'], self::MAX_TITULO),
                // La duracion primero, igual que en la lista que manda el
                // bot: es lo que decide si cabe hoy. Quien sale del
                // trabajo a las 5:30 necesita saber si alcanza antes que
                // cuanto cuesta.
                'description' => $s['duracion'].' min · '.$s['precio'],
                'next' => 'srv_'.$s['id'],
            ], array_slice($categoria['servicios'], 0, self::MAX_FILAS)),
        ];
    }

    /**
     * Elegido el servicio, el flujo se retira y deja hablar al bot.
     *
     * El nodo NO pregunta con botones la fecha a proposito: los dias
     * posibles no son una lista corta, y "el jueves si puedo antes de
     * las 3" es justo lo que un arbol de flujo no sabe leer y el modelo
     * si. Ahi termina lo estructurado y empieza la conversacion.
     */
    private function nodoDeServicio(array $servicio): array
    {
        return [
            'type' => 'message',
            'text' => "*{$servicio['nombre']}* — {$servicio['precio']} ({$servicio['duracion']} min).\n\n"
                .'¿Para qué día te sirve? Dime el día y te muestro las horas libres 😊',
        ];
    }

    private function resumen(array $servicios): string
    {
        return mb_substr(implode(', ', array_column(array_slice($servicios, 0, 3), 'nombre')), 0, 72);
    }

    /** @return Collection<int, array{id: int, nombre: string, servicios: list<array>}> */
    private function categoriasConServicios(Business $business): Collection
    {
        /*
         * Ordenado por lo que de verdad pide la gente, igual que la lista
         * que manda el bot: una lista de WhatsApp aguanta diez filas y
         * Manicure tiene veintitres, asi que el orden decide que ve la
         * clienta y que no. Con el alfabeto veia "Cambio de esmalte"
         * antes que Tradicional y Semipermanente, que son mil de las
         * citas del local.
         */
        $servicios = LoQueMasPiden::ordenar(
            $business->id,
            Service::withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->where('is_bookable_online', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        );

        $categorias = ServiceCategory::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->keyBy('id');

        return $servicios
            ->groupBy('service_category_id')
            ->filter(fn ($grupo, $categoriaId) => $categorias->has($categoriaId))
            ->map(fn ($grupo, $categoriaId) => [
                'id' => (int) $categoriaId,
                'nombre' => $categorias[$categoriaId]->name,
                'servicios' => $grupo->map(fn (Service $s) => [
                    'id' => $s->id,
                    'nombre' => $s->name,
                    'precio' => number_format((float) $s->price, 0, ',', '.').' '.($business->currency ?? 'COP'),
                    'duracion' => $s->duration_min,
                ])->values()->all(),
            ])
            ->values();
    }
}
