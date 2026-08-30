<?php

namespace App\Services\Loyalty;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyStamp;
use App\Support\Money\LoyaltyCalculator;
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
            ->with('rewardService')
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
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
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
     * Entrega los premios que el saldo ya alcance, y reinicia la tarjeta.
     *
     * Puede entregar mas de uno: si alguien acumulo doce sellos con una
     * tarjeta de cinco, se gano dos premios y le quedan dos sellos.
     *
     * @return list<LoyaltyReward>
     */
    public function unlockIfComplete(Client $client, LoyaltyProgram $program): array
    {
        $required = (int) $program->stamps_required;
        $entregados = [];

        return DB::transaction(function () use ($client, $program, $required, $entregados) {
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

        $progress = LoyaltyCalculator::progress($this->balance($client, $program), (int) $program->stamps_required);

        return [
            'program' => [
                'id' => $program->id,
                'name' => $program->name,
                'terms' => $program->terms,
                'stamps_required' => (int) $program->stamps_required,
                'reward_label' => $program->rewardLabel(),
                'min_ticket' => (float) $program->min_ticket,
            ],
        ] + $progress + [
            'rewards' => $this->availableRewards($client)->map(fn (LoyaltyReward $r) => [
                'id' => $r->id,
                'label' => $r->label(),
                'unlocked_at' => $r->unlocked_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
