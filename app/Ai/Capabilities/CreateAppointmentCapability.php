<?php

namespace App\Ai\Capabilities;

use App\Ai\AiArgumentException;
use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\Resolves;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\ClientResolver;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\OutsideWorkingHoursException;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use Carbon\CarbonImmutable;

/**
 * Agendar. La escritura que justifica todo el agente.
 *
 * Dos cosas que NO hace, y son las importantes:
 *
 * 1. No agenda a nombre de un tercero. Para una clienta, la ficha es la del
 *    telefono que escribio. Aunque el modelo mande otro nombre, la cita es
 *    suya: si no, basta escribirle al bot "agéndale a Carolina el sábado"
 *    para meterle citas falsas a la agenda de un local.
 *
 * 2. No reimplementa la reserva. Llama a `BookingService::book()`, que es
 *    quien reclama el horario contra el indice unico de `resource_occupancy`
 *    -- la garantia anti-solape del producto. Si el hueco se fue mientras la
 *    conversacion iba, esto devuelve un "ya no esta" y el agente ofrece otra
 *    hora, en vez de crear una cita encima de otra.
 */
class CreateAppointmentCapability implements Capability
{
    use Resolves;

    public function __construct(
        private readonly BookingService $booking,
        private readonly ClientResolver $clients,
    ) {}

    public function requiredPermission(): ?string
    {
        return 'citas.crear';
    }

    public function requiredFeature(): ?string
    {
        return 'online_booking';
    }

    public function allowsCustomers(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'servicio' => ['required', 'string', 'max:255'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'empleado' => ['nullable', 'string', 'max:255'],
            'sede' => ['nullable', 'string', 'max:255'],
            // Solo se usa para NOMBRAR una ficha nueva. Nunca para elegir a
            // quien se le agenda: eso lo decide el telefono.
            'cliente' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();

        $servicio = $this->resolveService($business->id, $arguments['servicio']);
        $sede = $this->resolveLocation($business->id, $arguments['sede'] ?? null);

        $persona = isset($arguments['empleado'])
            ? $this->resolveResource($business->id, $arguments['empleado'], $sede?->id)
            : $this->anyResourceFor($servicio->id, $sede?->id);

        $inicio = CarbonImmutable::parse($arguments['fecha'].' '.$arguments['hora'].':00', $tz);

        [$client, $nombre, $telefono] = $this->whoFor($caller, $arguments);

        try {
            $cita = $this->booking->book(
                $business,
                [[
                    'service_id' => $servicio->id,
                    'resource_id' => $persona->id,
                    'starts_at' => $inicio,
                ]],
                $client,
                $nombre,
                $telefono,
                Appointment::SOURCE_WHATSAPP_AGENT,
            );
        } catch (SlotUnavailableException) {
            /*
             * No es un error: es informacion que el agente puede usar. Se
             * devuelve como dato para que ofrezca otra hora en vez de
             * disculparse con un fallo tecnico.
             */
            return [
                'agendada' => false,
                'motivo' => 'Esa hora ya se ocupó mientras conversábamos. Ofrece otra hora del mismo día.',
            ];
        } catch (OutsideWorkingHoursException|\DomainException $e) {
            return ['agendada' => false, 'motivo' => $e->getMessage()];
        }

        return [
            'agendada' => true,
            'id' => $cita->id,
            'servicio' => $servicio->name,
            'con' => $persona->name,
            'sede' => $sede?->name,
            'fecha' => $cita->starts_at?->setTimezone($tz)->format('Y-m-d'),
            'hora' => $cita->starts_at?->setTimezone($tz)->format('H:i'),
            'precio' => (float) $servicio->price,
        ];
    }

    /**
     * A nombre de quien va la cita.
     *
     * @return array{0: ?Client, 1: ?string, 2: ?string}
     */
    private function whoFor(AiCaller $caller, array $arguments): array
    {
        if ($caller->isStaff()) {
            // Desde el panel si tiene sentido agendarle a alguien mas: quien
            // lo pide es una empleada del negocio, con permiso `citas.crear`.
            $nombre = trim((string) ($arguments['cliente'] ?? ''));

            if ($nombre === '') {
                throw new AiArgumentException('Falta el nombre de la clienta.');
            }

            return [null, $nombre, null];
        }

        // Clienta: su ficha, o una nueva con su telefono. El nombre del
        // argumento solo sirve para estrenarla.
        if ($caller->client !== null) {
            return [$caller->client, $caller->client->fullName(), $caller->phone];
        }

        $nombre = trim((string) ($arguments['cliente'] ?? ''));

        if ($nombre === '') {
            throw new AiArgumentException('Pregúntale su nombre antes de agendar.');
        }

        $ficha = $this->clients->resolve($caller->business->id, null, $nombre, $caller->phone);

        return [$ficha, $nombre, $caller->phone];
    }

    /** La primera persona activa que presta ese servicio en esa sede. */
    private function anyResourceFor(int $serviceId, ?int $locationId): \App\Models\Resource
    {
        $recurso = \App\Models\Resource::withoutGlobalScopes()
            ->where('type', \App\Models\Resource::TYPE_STAFF)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->whereHas('services', fn ($q) => $q->where('services.id', $serviceId))
            ->orderBy('sort_order')
            ->first();

        if ($recurso === null) {
            throw new AiArgumentException('Nadie presta ese servicio en esa sede.');
        }

        return $recurso;
    }
}
