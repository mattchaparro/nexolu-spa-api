<?php

namespace App\Ai\Capabilities\Panel;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Models\Appointment;
use App\Models\Message;
use App\Models\WhatsappConversation;
use Carbon\CarbonImmutable;

/**
 * Las que escriben pero no agendan.
 *
 * Quien pregunta y no termina en cita es la venta que se escapó: a esa hay
 * que escribirle, ofrecerle una hora, preguntarle qué le faltó. Cuenta los
 * mensajes que MANDÓ la clienta en el periodo y descarta a quien agendó en
 * ese tiempo o tiene una cita por venir.
 *
 * Es de administración y no de la clienta: enumera clientas, así que nunca
 * se abre al WhatsApp (allowsCustomers false).
 */
class ChattyNonBookersCapability implements Capability
{
    public function requiredPermission(): ?string
    {
        return 'clientes.ver';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function allowsCustomers(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return ['dias' => ['nullable', 'integer', 'min:1', 'max:180']];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();
        $dias = (int) ($arguments['dias'] ?? 30);
        $desde = CarbonImmutable::now($tz)->subDays($dias)->startOfDay();

        $porConversacion = Message::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('direction', Message::DIRECTION_IN)
            ->whereNotNull('conversation_id')
            ->where('created_at', '>=', $desde->utc())
            ->selectRaw('conversation_id, count(*) as mensajes, max(created_at) as ultimo')
            ->groupBy('conversation_id')
            ->orderByDesc('mensajes')
            ->limit(200)
            ->get();

        $conversaciones = WhatsappConversation::withoutGlobalScopes()
            ->whereIn('id', $porConversacion->pluck('conversation_id'))
            ->with('client')
            ->get()
            ->keyBy('id');

        $resultado = [];

        foreach ($porConversacion as $fila) {
            $conv = $conversaciones->get($fila->conversation_id);

            if ($conv === null) {
                continue;
            }

            $citas = Appointment::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where(fn ($q) => $q
                    ->where('client_phone', $conv->phone)
                    ->when($conv->client_id, fn ($qq) => $qq->orWhere('client_id', $conv->client_id)));

            // Agendó en el periodo, o tiene una por venir: no es una venta perdida.
            $agendo = (clone $citas)->where('created_at', '>=', $desde->utc())->exists()
                || (clone $citas)->where('starts_at', '>=', now())
                    ->where('status', '!=', Appointment::STATUS_CANCELLED)->exists();

            if ($agendo) {
                continue;
            }

            $ultimaVisita = (clone $citas)->whereNotNull('checked_out_at')->max('starts_at');

            $resultado[] = [
                'nombre' => trim(($conv->client?->name ?? '').' '.($conv->client?->last_name ?? '')) ?: 'Sin nombre',
                'telefono' => '+'.ltrim($conv->phone, '+'),
                'mensajes' => (int) $fila->mensajes,
                'ultimo_mensaje' => CarbonImmutable::parse($fila->ultimo)->setTimezone($tz)->toDateString(),
                'ultima_visita' => $ultimaVisita ? CarbonImmutable::parse($ultimaVisita)->setTimezone($tz)->toDateString() : null,
            ];

            if (count($resultado) >= 20) {
                break;
            }
        }

        return [
            'ultimos_dias' => $dias,
            'total' => count($resultado),
            'clientas' => $resultado,
        ];
    }
}
