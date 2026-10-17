<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\AiCaller;
use App\Ai\Capabilities\AvailabilityCapability;
use App\Ai\FechaDicha;
use App\Ai\UltimoPedido;
use App\Models\Client;
use App\Models\Resource;
use App\Models\Service;
use App\Services\Messaging\MessageDispatcher;
use App\Services\Scheduling\AvailabilityService;
use App\Support\ChannelPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Mándale los horarios a esta clienta por WhatsApp.»
 *
 * Quien administra elige la clienta, el servicio, el día y --si quiere-- con
 * quién, y a la clienta le llegan las horas libres como botones en su chat.
 * Toca una y el bot sigue solo: confirma y agenda. La cita termina allá, sin
 * que nadie del local tenga que escribir horas a mano.
 *
 * Es el mismo envío que hace el bot cuando una clienta pide horas (misma
 * lista, mismo estado guardado), así que lo que toque después lo atiende el
 * camino de siempre (Toques / crear_cita).
 *
 * Solo con la ventana de 24 horas abierta: WhatsApp no entrega botones fuera
 * de ella, y mandarlos igual se vería enviado sin llegar nunca.
 */
class SendAvailabilityController
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        $data = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'phone' => ['nullable', 'string', 'max:32'],
            'service_id' => ['required', 'integer'],
            'resource_id' => ['nullable', 'integer'],
            // «mañana», «viernes» o 2026-10-20: la misma fecha que entiende el bot.
            'date' => ['required', 'string', 'max:40'],
        ]);

        $client = ! empty($data['client_id'])
            ? Client::where('business_id', $business->id)->find($data['client_id'])
            : null;

        $phone = ChannelPhone::normalize((string) ($client?->phone ?? $data['phone'] ?? ''), $business->country_code ?? 'CO');

        if ($phone === null) {
            return $this->fail('Falta el WhatsApp de la clienta: elige una ficha con teléfono o escríbelo.');
        }

        $service = Service::where('business_id', $business->id)->where('is_active', true)->find($data['service_id']);

        if ($service === null) {
            return $this->fail('Ese servicio no existe o está apagado.');
        }

        $resource = null;

        if (! empty($data['resource_id'])) {
            $resource = Resource::where('business_id', $business->id)->where('is_active', true)->find($data['resource_id']);

            if ($resource === null || ! $service->resources()->whereKey($resource->id)->exists()) {
                return $this->fail('Esa persona no hace ese servicio.');
            }
        }

        $date = FechaDicha::resolver($data['date'], $tz);

        if ($date === null) {
            return $this->fail("No entendí el día «{$data['date']}».");
        }

        if ($date->lt(now($tz)->startOfDay())) {
            return $this->fail('Ese día ya pasó.');
        }

        $slots = $this->availability->slotsForService($business, $service, $date, $resource, null, null);

        if ($slots === []) {
            return $this->fail(sprintf(
                'No hay horas libres el %s para %s%s.',
                $date->locale('es')->isoFormat('dddd D [de] MMMM'),
                $service->name,
                $resource ? ' con '.$resource->name : '',
            ));
        }

        // Antes de tocar su conversación: sin ventana los botones no llegan.
        if (! $this->dispatcher->windowIsOpenFor($business, $phone)) {
            return $this->fail(
                'Pasaron más de 24 horas desde su último mensaje y WhatsApp no deja mandar botones. '
                .'Pídele que te escriba (o contéstale por el chat) y vuelve a enviarlos.',
                409,
            );
        }

        /*
         * Un pedido limpio: lo que la clienta estuviera haciendo antes (otro
         * servicio, otra persona descartada) no se mezcla con esto. Y sin
         * preguntarle «¿con quién?»: quien administra ya lo decidió.
         */
        UltimoPedido::olvidar($phone);
        UltimoPedido::guardar($phone, ['empleado_preguntado' => true]);

        $caller = AiCaller::customer(
            $business,
            $phone,
            $client ?? Client::where('business_id', $business->id)->where('phone', $phone)->first(),
            'whatsapp',
        );

        app(AvailabilityCapability::class)->execute($caller, array_filter([
            'servicio' => $service->name,
            'fecha' => $date->format('Y-m-d'),
            'empleado' => $resource?->name,
        ]));

        // Salió si la lista quedó marcada como mostrada (con Flow o con botones).
        if (empty(UltimoPedido::ver($phone)['mostrado_at'])) {
            return $this->fail('No se pudieron enviar los horarios. Revisa que el WhatsApp esté conectado y vuelve a intentar.', 502);
        }

        return response()->json([
            'sent' => true,
            'hours' => count($slots),
            'day' => $date->locale('es')->isoFormat('dddd D [de] MMMM'),
        ]);
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
