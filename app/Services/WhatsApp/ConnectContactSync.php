<?php

namespace App\Services\WhatsApp;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Support\ChannelPhone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Las difusiones ahora se arman en Connect, y Connect filtra con lo que el
 * Spa le cuenta de cada clienta: si acepta promociones, cuándo vino por
 * última vez, cuántas veces y con quién.
 *
 * La ficha sigue siendo del Spa. Esto solo publica una copia de esos
 * datos en el contacto de Connect (PUT /v1/contacts/bulk), que los mezcla
 * con lo que ya tenga el contacto sin pisar lo que pusieron los flujos.
 *
 * Los nombres de los campos son el contrato con Connect (ver
 * core/broadcasts.py en nexolu-comms-api):
 *   acepta_promociones, ultima_visita (Y-m-d), visitas, ultima_atencion_con
 */
class ConnectContactSync
{
    private const BATCH = 500;

    public function __construct(private readonly ConnectChat $connect) {}

    /** @return int cuántas fichas se publicaron */
    public function syncBusiness(Business $business): int
    {
        if (! $this->connect->isConfigured()) {
            return 0;
        }

        $publicadas = 0;

        Client::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->chunkById(self::BATCH, function (Collection $clientes) use ($business, &$publicadas) {
                $contactos = $this->payload($business, $clientes);
                $this->push($business, $contactos);
                $publicadas += count($contactos);
            });

        return $publicadas;
    }

    public function syncClient(Client $client): void
    {
        if (! $this->connect->isConfigured() || empty($client->phone)) {
            return;
        }

        $business = Business::find($client->business_id);

        if ($business !== null) {
            $this->push($business, $this->payload($business, collect([$client])));
        }
    }

    /**
     * @param  Collection<int, Client>  $clientes
     * @return list<array<string, mixed>>
     */
    public function payload(Business $business, Collection $clientes): array
    {
        $ids = $clientes->pluck('id')->all();

        // La última visita COBRADA de cada una, con quién la atendió. Una
        // cita agendada y no atendida no dice nada de nadie.
        $ultimas = Appointment::withoutGlobalScope('business')
            ->whereIn('client_id', $ids)
            ->whereNotNull('checked_out_at')
            ->orderByDesc('checked_out_at')
            ->with(['items' => fn ($q) => $q->withoutGlobalScope('business')->orderBy('sort_order'), 'items.resource' => fn ($q) => $q->withoutGlobalScope('business')])
            ->get(['id', 'client_id', 'checked_out_at'])
            ->unique('client_id')
            ->keyBy('client_id');

        $visitas = Appointment::withoutGlobalScope('business')
            ->whereIn('client_id', $ids)
            ->whereNotNull('checked_out_at')
            ->selectRaw('client_id, count(*) as total')
            ->groupBy('client_id')
            ->pluck('total', 'client_id');

        $pais = $business->country_code ?: 'CO';
        $zona = $business->timezone ?: config('app.timezone');

        $contactos = [];
        foreach ($clientes as $cliente) {
            $telefono = ChannelPhone::normalize((string) $cliente->phone, $pais);
            if ($telefono === null) {
                continue;
            }

            $ultima = $ultimas->get($cliente->id);
            $atendio = $ultima?->items->first()?->resource?->name;

            $contactos[] = [
                'phone' => $telefono,
                'name' => $cliente->fullName(),
                'fields' => [
                    'acepta_promociones' => (bool) $cliente->accepts_marketing && (bool) ($cliente->is_active ?? true) && $cliente->deleted_at === null,
                    'ultima_visita' => $ultima?->checked_out_at?->setTimezone($zona)->toDateString(),
                    'visitas' => (int) ($visitas[$cliente->id] ?? 0),
                    'ultima_atencion_con' => $atendio,
                ],
            ];
        }

        return $contactos;
    }

    /** @param  list<array<string, mixed>>  $contactos */
    private function push(Business $business, array $contactos): void
    {
        if ($contactos === []) {
            return;
        }

        Http::withToken((string) config('services.comms_core.api_key'))
            ->acceptJson()
            ->timeout(30)
            ->baseUrl(rtrim((string) config('services.comms_core.base_url'), '/'))
            ->put('/v1/contacts/bulk', [
                'business_id' => (string) $business->id,
                'contacts' => $contactos,
            ])
            ->throw();
    }
}
