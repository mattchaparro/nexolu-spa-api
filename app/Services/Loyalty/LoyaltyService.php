<?php

namespace App\Services\Loyalty;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyStamp;
use App\Models\LoyaltyTier;
use App\Support\Money\LoyaltyCalculator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * La tarjeta de sellos: ganar, desbloquear y canjear.
 *
 * El sello se gana AL COBRAR, automaticamente, y no como una accion opcional
 * del flujo de etapas. Un programa de fidelizacion que solo funciona si el
 * negocio se acordo de cablearlo en su workflow es un programa que para la
 * mayoria no funciona en silencio -- y de eso se entera la clienta en el
 * mostrador, pidiendo un premio que el sistema nunca le conto.
 */
class LoyaltyService
{
    /** El programa vigente de un negocio, o null si no tiene. */
    public function activeProgram(Business $business): ?LoyaltyProgram
    {
        if (! $business->hasFeature('loyalty')) {
            return null;
        }

        return LoyaltyProgram::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->with(['rewardService', 'tiers.rewardService'])
            ->first();
    }

    /**
     * Cuenta la visita, si corresponde.
     *
     * Devuelve el sello nuevo, o null si no habia programa, no habia ficha de
     * cliente, la visita no llego al minimo, o esa cita ya tenia sello.
     */
    public function earnFor(Appointment $appointment): ?LoyaltyStamp
    {
        $business = $appointment->business;
        $program = $this->activeProgram($business);

        if ($program === null) {
            return null;
        }

        /*
         * Sin ficha de cliente no hay a quien sumarle.
         *
         * No se inventa una: quien reserva sin dejar datos no tiene donde
         * acumular, y crear fichas fantasma para no perder el sello llenaria
         * la base de clientes que no existen.
         */
        if ($appointment->client_id === null) {
            return null;
        }

        $total = (float) ($appointment->total ?? 0);

        if (! LoyaltyCalculator::earnsStamp($total, (float) $program->min_ticket)) {
            return null;
        }

        try {
            $stamp = LoyaltyStamp::create([
                'business_id' => $business->id,
                'program_id' => $program->id,
                'client_id' => $appointment->client_id,
                'appointment_id' => $appointment->id,
                'earned_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Esa cita ya tenia sello. Pasa de verdad: deshacer un cobro y
             * volver a cobrarlo entra por aca dos veces.
             *
             * Se traga en silencio porque el resultado deseado ya se cumplio
             * -- la visita esta contada una vez -- y hacer fallar el cobro por
             * esto seria dejar sin cobrar una cita por culpa de un sello.
             */
            return null;
        }

        $this->unlockIfComplete($appointment->client, $program);

        return $stamp;
    }

    /**
     * Los sellos que todavia cuentan para el saldo.
     *
     * Se CUENTAN, no se leen de un contador guardado: no hay nada que se pueda
     * desincronizar de la realidad.
     *
     * En modo escalera esto es simplemente el total de visitas contadas: alli
     * ningun sello se consume nunca, asi que el filtro no descarta ninguno.
     */
    public function balance(Client $client, LoyaltyProgram $program): int
    {
        return LoyaltyStamp::withoutGlobalScope('business')
            ->where('program_id', $program->id)
            ->where('client_id', $client->id)
            ->whereNull('consumed_by_reward_id')
            ->count();
    }

    /**
     * Entrega los premios que el saldo ya alcance.
     *
     * Que hace exactamente depende del modo del programa, y son dos cosas
     * distintas de verdad -- no dos variantes de la misma.
     *
     * @return list<LoyaltyReward>
     */
    public function unlockIfComplete(Client $client, LoyaltyProgram $program): array
    {
        return $program->isLadder()
            ? $this->unlockLadder($client, $program)
            : $this->unlockCard($client, $program);
    }

    /**
     * Modo tarjeta: cada N sellos, un premio, y la tarjeta vuelve a cero.
     *
     * Puede entregar mas de uno: si alguien acumulo doce sellos con una
     * tarjeta de cinco, se gano dos premios y le quedan dos sellos.
     *
     * @return list<LoyaltyReward>
     */
    private function unlockCard(Client $client, LoyaltyProgram $program): array
    {
        $required = (int) $program->stamps_required;

        return DB::transaction(function () use ($client, $program, $required) {
            /*
             * Se bloquean los sellos disponibles antes de consumirlos: dos
             * cobros a la vez leerian el mismo saldo y entregarian dos premios
             * por una sola tarjeta.
             */
            $disponibles = LoyaltyStamp::withoutGlobalScope('business')
                ->where('program_id', $program->id)
                ->where('client_id', $client->id)
                ->whereNull('consumed_by_reward_id')
                ->orderBy('earned_at')
                ->lockForUpdate()
                ->get();

            $veces = LoyaltyCalculator::completedCards($disponibles->count(), $required);
            $entregados = [];

            for ($i = 0; $i < $veces; $i++) {
                $reward = LoyaltyReward::create([
                    'business_id' => $program->business_id,
                    'program_id' => $program->id,
                    'client_id' => $client->id,
                    'status' => LoyaltyReward::STATUS_AVAILABLE,
                    'unlocked_at' => now(),
                    // Congelado, como el precio de una cita cobrada.
                    'reward_type' => $program->reward_type,
                    'reward_value' => $program->reward_value,
                    'reward_service_id' => $program->reward_service_id,
                    'reward_note' => $program->reward_note,
                ]);

                // Los sellos mas viejos primero: la tarjeta se llena en orden.
                $aConsumir = $disponibles->slice($i * $required, $required)->pluck('id');

                LoyaltyStamp::withoutGlobalScope('business')
                    ->whereIn('id', $aConsumir)
                    ->update(['consumed_by_reward_id' => $reward->id]);

                $entregados[] = $reward;
            }

            return $entregados;
        });
    }

    /**
     * Modo escalera: cada hito entrega su premio, y los sellos no se gastan.
     *
     * TRES DECISIONES QUE NO SON OBVIAS:
     *
     * 1. El escalon se alcanza con `>=`, no con `==`. El sistema viejo de
     *    Luxury exige igualdad exacta, y por eso una clienta cuyo contador
     *    salta de 4 a 6 -- un ajuste a mano, una migracion -- se queda sin el
     *    premio de las 5 para siempre. Aca basta con haberlo pasado.
     *
     * 2. Un escalon entrega UNA vez, y lo garantiza el indice unico
     *    (tier_id, client_id). Sin eso, como los sellos no se gastan, el
     *    saldo seguiria por encima del hito y cada cobro posterior volveria a
     *    desbloquearlo.
     *
     * 3. Los premios anteriores NO se vencen. El sistema viejo si los vence:
     *    al desbloquear el de 10, el de 5 sin usar pasa a `expired`. Alli
     *    tenia una razon -- nada marcaba un premio como usado, asi que el
     *    vencimiento hacia de limite. Aca el canje se registra, y quitarle a
     *    una clienta un premio que se gano y todavia no uso seria retirarle
     *    una promesa que le hizo el local.
     *
     * @return list<LoyaltyReward>
     */
    private function unlockLadder(Client $client, LoyaltyProgram $program): array
    {
        return DB::transaction(function () use ($client, $program) {
            $sellos = LoyaltyStamp::withoutGlobalScope('business')
                ->where('program_id', $program->id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->count();

            $yaEntregados = LoyaltyReward::withoutGlobalScope('business')
                ->where('client_id', $client->id)
                ->whereNotNull('tier_id')
                ->pluck('tier_id')
                ->all();

            $escalones = LoyaltyTier::withoutGlobalScope('business')
                ->where('program_id', $program->id)
                ->where('is_active', true)
                ->where('stamps_required', '<=', $sellos)
                ->when($yaEntregados !== [], fn ($q) => $q->whereNotIn('id', $yaEntregados))
                ->orderBy('stamps_required')
                ->get();

            $entregados = [];

            foreach ($escalones as $escalon) {
                try {
                    $entregados[] = LoyaltyReward::create([
                        'business_id' => $program->business_id,
                        'program_id' => $program->id,
                        'tier_id' => $escalon->id,
                        'client_id' => $client->id,
                        'status' => LoyaltyReward::STATUS_AVAILABLE,
                        'unlocked_at' => now(),
                        // Congelado: si el negocio cambia el escalon manana, a
                        // quien ya lo alcanzo se le entrega lo prometido.
                        'reward_type' => $escalon->reward_type,
                        'reward_value' => $escalon->reward_value,
                        'reward_service_id' => $escalon->reward_service_id,
                        'reward_note' => $escalon->reward_note,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    /*
                     * Otro cobro simultaneo gano la carrera y ya entrego este
                     * escalon. El resultado deseado se cumplio -- el premio
                     * existe, una sola vez -- asi que no hay nada que hacer.
                     */
                }
            }

            return $entregados;
        });
    }

    /**
     * Los premios que un cliente puede usar hoy.
     *
     * @return \Illuminate\Support\Collection<int, LoyaltyReward>
     */
    public function availableRewards(Client $client): \Illuminate\Support\Collection
    {
        return LoyaltyReward::withoutGlobalScope('business')
            ->where('business_id', $client->business_id)
            ->where('client_id', $client->id)
            ->where('status', LoyaltyReward::STATUS_AVAILABLE)
            ->with('rewardService')
            ->orderBy('unlocked_at')
            ->get();
    }

    /** Marca un premio como usado en una cita. */
    public function markUsed(LoyaltyReward $reward, Appointment $appointment): LoyaltyReward
    {
        if ($reward->status !== LoyaltyReward::STATUS_AVAILABLE) {
            throw new \DomainException('Ese premio ya no está disponible.');
        }

        $reward->update([
            'status' => LoyaltyReward::STATUS_USED,
            'used_at' => now(),
            'used_on_appointment_id' => $appointment->id,
        ]);

        return $reward->fresh();
    }

    /** Devuelve un premio a disponible, para deshacer un cobro. */
    public function release(Appointment $appointment): void
    {
        LoyaltyReward::withoutGlobalScope('business')
            ->where('used_on_appointment_id', $appointment->id)
            ->update([
                'status' => LoyaltyReward::STATUS_AVAILABLE,
                'used_at' => null,
                'used_on_appointment_id' => null,
            ]);
    }

    /**
     * Como va la tarjeta de un cliente, para mostrarla.
     *
     * @return array<string, mixed>|null
     */
    public function cardFor(Client $client): ?array
    {
        $program = $this->activeProgram($client->business);

        if ($program === null) {
            return null;
        }

        $sellos = $this->balance($client, $program);

        $base = [
            'program' => [
                'id' => $program->id,
                'name' => $program->name,
                'mode' => $program->mode,
                'terms' => $program->terms,
                'stamps_required' => (int) $program->stamps_required,
                'reward_label' => $program->rewardLabel(),
                'min_ticket' => (float) $program->min_ticket,
            ],
        ];

        $progreso = $program->isLadder()
            ? $this->progresoEscalera($client, $program, $sellos)
            : LoyaltyCalculator::progress($sellos, (int) $program->stamps_required);

        /*
         * En la escalera, el premio que se anuncia es el del SIGUIENTE hito,
         * no el del programa. El programa guarda el primer escalon solo para
         * que su fila suelta sea coherente; anunciarlo diria "le faltan 3 para
         * 10%" cuando lo que viene es el 15%.
         */
        if ($program->isLadder() && isset($progreso['next_tier']['reward_label'])) {
            $base['program']['reward_label'] = $progreso['next_tier']['reward_label'];
        }

        return $base + $progreso + [
            'rewards' => $this->availableRewards($client)->map(fn (LoyaltyReward $r) => [
                'id' => $r->id,
                'label' => $r->label(),
                'unlocked_at' => $r->unlocked_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * La escalera completa, para pintarla.
     *
     * Se devuelven TODOS los escalones y no solo el siguiente, porque lo que
     * hace volver a una clienta es ver que a las 30 hay un producto -- no
     * enterarse de a uno.
     *
     * @return array<string, mixed>
     */
    private function progresoEscalera(Client $client, LoyaltyProgram $program, int $sellos): array
    {
        $entregados = LoyaltyReward::withoutGlobalScope('business')
            ->where('client_id', $client->id)
            ->whereNotNull('tier_id')
            ->pluck('status', 'tier_id')
            ->all();

        $siguiente = null;

        $escalones = $program->tiers->map(function (LoyaltyTier $t) use ($sellos, $entregados, &$siguiente) {
            $alcanzado = $sellos >= (int) $t->stamps_required;

            if (! $alcanzado && $siguiente === null) {
                $siguiente = $t;
            }

            return [
                'stamps_required' => (int) $t->stamps_required,
                'reward_label' => $t->rewardLabel(),
                'reached' => $alcanzado,
                // `null` = alcanzado pero sin premio emitido todavia, que solo
                // pasa entre el cobro y el desbloqueo.
                'status' => $entregados[$t->id] ?? null,
            ];
        })->values()->all();

        /*
         * `required`, `remaining` y `complete` se devuelven TAMBIEN en modo
         * escalera, apuntando al siguiente hito.
         *
         * No es relleno: es lo que lee la pantalla de cobro para decir "7 de
         * 10 sellos, le faltan 3". Sin ellos esa linea mostraria "7 de
         * undefined" en cada cobro de Luxury. Que la misma forma sirva para
         * los dos modos es lo que permite que la escalera funcione sin tocar
         * el front.
         */
        return [
            'stamps' => $sellos,
            'required' => $siguiente === null ? $sellos : (int) $siguiente->stamps_required,
            'remaining' => $siguiente === null ? 0 : (int) $siguiente->stamps_required - $sellos,
            'complete' => $siguiente === null,
            'tiers' => $escalones,
            'next_tier' => $siguiente === null ? null : [
                'stamps_required' => (int) $siguiente->stamps_required,
                'reward_label' => $siguiente->rewardLabel(),
                'stamps_away' => (int) $siguiente->stamps_required - $sellos,
            ],
        ];
    }
}
