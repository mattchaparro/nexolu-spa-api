<?php

namespace App\Services\Migration\Importadores;

use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Support\Money\LoyaltyCalculator;
use Illuminate\Support\Facades\DB;

/**
 * La tarjeta de sellos: la escalera, los sellos y los premios ya ganados.
 *
 * Corre DESPUES del historial, porque cada sello cuelga de una cita migrada.
 * Un sello sin visita es un numero que nadie puede auditar, y eso es
 * exactamente lo que el sistema viejo termino arreglando a mano con su
 * comando `gamification:recalculate`.
 *
 * LOS SELLOS SALEN DE LAS CITAS, NO DEL CONTADOR VIEJO. `loyalty_cards.stamps`
 * es un entero suelto que alla se desincronizaba y habia que recalcular; aca
 * el saldo se cuenta, asi que se reconstruye desde las visitas y queda
 * correcto por construccion. De paso cuadra: la suma de sellos del legacy es
 * 1.218 y las atenciones con clienta son 1.206.
 *
 * POR QUE SE RELLENAN PREMIOS VENCIDOS: el sistema viejo solo crea la fila de
 * un premio cuando el contador cae EXACTO en el hito, y ademas vence el
 * anterior al llegar el siguiente. Asi que una clienta con 20 visitas puede
 * tener una sola fila -- la de 20 -- y ningun rastro de las de 5, 10 y 15.
 * Si esos escalones se dejan en blanco, el primer cobro que haga en el
 * sistema nuevo le desbloquea los tres de golpe. Se anotan como vencidos, que
 * es el estado en que de verdad estan.
 */
class ImportaFidelizacion extends Importador
{
    public function nombre(): string
    {
        return 'Fidelizacion';
    }

    public function correr(): void
    {
        $programa = $this->programa();

        if ($programa === null) {
            return;
        }

        $this->sellos($programa);
        $this->premios($programa);
    }

    /**
     * El programa en modo escalera, con los escalones del sistema viejo.
     *
     * Si ya existe uno activo no se toca: el negocio pudo ajustarlo aca, y
     * reimportar cada noche le desharia el cambio.
     */
    private function programa(): ?LoyaltyProgram
    {
        $existente = LoyaltyProgram::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->where('is_active', true)
            ->first();

        if ($existente !== null) {
            $this->reporte->saltado('Fidelizacion');

            return $existente;
        }

        $escalones = $this->legacy('loyalty_rewards')
            ->where('is_active', true)
            ->orderBy('required_stamps')
            ->get();

        if ($escalones->isEmpty()) {
            $this->reporte->aviso('Fidelizacion', 'El sistema viejo no tiene escalones configurados.');

            return null;
        }

        if ($this->simular) {
            $this->reporte->creado('Fidelizacion');

            return null;
        }

        return DB::transaction(function () use ($escalones) {
            $programa = LoyaltyProgram::create([
                'business_id' => $this->business->id,
                'name' => 'Tarjeta de sellos',
                'mode' => LoyaltyProgram::MODE_LADDER,
                'stamps_required' => (int) $escalones->first()->required_stamps,
                'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT,
                'reward_value' => (float) $escalones->first()->value,
                'min_ticket' => 0,
                'is_active' => true,
            ]);

            foreach ($escalones as $escalon) {
                $tier = LoyaltyTier::create([
                    'business_id' => $this->business->id,
                    'program_id' => $programa->id,
                    'stamps_required' => (int) $escalon->required_stamps,
                    'reward_type' => $this->tipoDePremio($escalon),
                    /*
                     * El regalo no lleva valor: lo que hay que entregar se
                     * dice con palabras, y esas palabras son las mismas que la
                     * clienta ya leyo en el sistema viejo ("un producto de
                     * nuestra marca al azar"). Inventarle un precio seria
                     * prometer algo distinto.
                     */
                    'reward_value' => $escalon->type === 'discount' ? (float) $escalon->value : null,
                    'reward_note' => $escalon->type === 'discount' ? null : $escalon->name,
                    'is_active' => true,
                ]);

                $this->anotar('loyalty_tier', (int) $escalon->id, $tier->id);
                $this->reporte->creado('Escalones');
            }

            $this->reporte->creado('Fidelizacion');

            return $programa;
        });
    }

    /**
     * El tipo equivalente aca.
     *
     * `product` del sistema viejo -- "un producto de nuestra marca al azar" --
     * entra como REGALO: no toca la cuenta, solo le dice a quien atiende que
     * hay algo que entregar. Es exactamente lo que pasaba alla, donde ese
     * premio tampoco tenia mecanica de precio.
     */
    private function tipoDePremio(object $escalon): string
    {
        return match ($escalon->type) {
            'discount' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT,
            default => LoyaltyCalculator::REWARD_GIFT,
        };
    }

    /**
     * Un sello por cada visita cobrada que si dice a quien se atendio.
     *
     * Las 2.118 atenciones sin clienta no dan sello, igual que en el sistema
     * viejo: no hay a quien sumarselas.
     *
     * Se insertan de a lotes y con `insertOrIgnore` porque el indice unico
     * (program_id, appointment_id) ya garantiza uno por visita: en la corrida
     * de manana los 1.206 de hoy chocan y se descartan solos, sin tener que
     * preguntar por cada uno.
     */
    private function sellos(LoyaltyProgram $programa): void
    {
        $yaTienen = DB::table('loyalty_stamps')
            ->where('program_id', $programa->id)
            ->pluck('appointment_id')
            ->all();

        $pendientes = DB::table('appointments')
            ->where('business_id', $this->business->id)
            ->where('status', 'completed')
            ->whereNotNull('client_id')
            ->when($yaTienen !== [], fn ($q) => $q->whereNotIn('id', $yaTienen))
            ->orderBy('id')
            ->get(['id', 'client_id', 'checked_out_at', 'starts_at']);

        if ($pendientes->isEmpty()) {
            $this->reporte->saltado('Sellos', count($yaTienen));

            return;
        }

        foreach ($pendientes->chunk(500) as $lote) {
            $filas = $lote->map(fn ($cita) => [
                'business_id' => $this->business->id,
                'program_id' => $programa->id,
                'client_id' => $cita->client_id,
                'appointment_id' => $cita->id,
                // La fecha de la visita, no la de la importacion: el orden en
                // que se ganaron es lo que explica una tarjeta.
                'earned_at' => $cita->checked_out_at ?? $cita->starts_at,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            DB::table('loyalty_stamps')->insertOrIgnore($filas);
        }

        $this->reporte->creado('Sellos', $pendientes->count());
    }

    /**
     * Los premios: los que el sistema viejo tiene vivos, y los que perdio.
     *
     * Cada escalon que la clienta ya paso queda anotado, con el estado en que
     * de verdad esta. Los `available` del legacy -- 74 promesas hechas que
     * nadie ha canjeado -- llegan como disponibles y se pueden redimir en el
     * primer cobro.
     */
    private function premios(LoyaltyProgram $programa): void
    {
        $escalones = LoyaltyTier::withoutGlobalScope('business')
            ->where('program_id', $programa->id)
            ->orderBy('stamps_required')
            ->get();

        if ($escalones->isEmpty()) {
            return;
        }

        $vivos = $this->premiosVivosDelLegacy();

        $saldos = DB::table('loyalty_stamps')
            ->where('program_id', $programa->id)
            ->groupBy('client_id')
            ->pluck(DB::raw('COUNT(*)'), 'client_id')
            ->all();

        /*
         * La union de las dos listas, y no solo quien tiene sellos.
         *
         * Hay clientas cuyo contador viejo iba POR DELANTE de sus visitas
         * atribuibles -- un ajuste a mano, una tarjeta creada con un numero
         * escrito -- y el sistema viejo ya les prometio un premio que sus
         * visitas de aca no alcanzan a justificar. Esa promesa se cumple: son
         * cuatro clientas, y ninguna tiene por que enterarse de que cambiamos
         * de sistema.
         */
        $clientes = array_unique(array_merge(array_keys($saldos), array_keys($vivos)));

        $creados = 0;

        foreach ($clientes as $clienteId) {
            $sellos = (int) ($saldos[$clienteId] ?? 0);

            foreach ($escalones as $escalon) {
                $hito = (int) $escalon->stamps_required;
                $prometido = isset($vivos[$clienteId][$hito]);

                if ($sellos < $hito && ! $prometido) {
                    continue;
                }

                $creados += DB::table('loyalty_rewards')->insertOrIgnore([
                    'business_id' => $this->business->id,
                    'program_id' => $programa->id,
                    'tier_id' => $escalon->id,
                    'client_id' => $clienteId,
                    /*
                     * Sin rastro en el legacy = vencido. Es el estado real:
                     * alla ese premio o se uso a mano o lo vencio el escalon
                     * siguiente. Marcarlo disponible le regalaria a la clienta
                     * un descuento que ya recibio.
                     */
                    'status' => $prometido
                        ? LoyaltyReward::STATUS_AVAILABLE
                        : LoyaltyReward::STATUS_EXPIRED,
                    'unlocked_at' => now(),
                    'reward_type' => $escalon->reward_type,
                    'reward_value' => $escalon->reward_value,
                    'reward_note' => $escalon->reward_note,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $disponibles = DB::table('loyalty_rewards')
            ->where('program_id', $programa->id)
            ->where('status', LoyaltyReward::STATUS_AVAILABLE)
            ->count();

        /*
         * Lo que ESTA corrida inserto, no el total acumulado.
         *
         * `insertOrIgnore` devuelve cuantas filas entraron de verdad: las que
         * choca el indice unico no cuentan. Reportar el total haria que la
         * corrida de manana dijera "110 premios creados" sin haber creado
         * ninguno, y esa es justo la cifra que uno mira para saber si el
         * mapeo se rompio.
         */
        if ($creados > 0) {
            $this->reporte->creado('Premios', $creados);
        } else {
            $this->reporte->saltado(
                'Premios',
                DB::table('loyalty_rewards')->where('program_id', $programa->id)->count(),
            );
        }

        if ($disponibles > 0) {
            $this->reporte->aviso(
                'Fidelizacion',
                "{$disponibles} premios quedaron DISPONIBLES: son promesas que el sistema viejo "
                .'ya hizo y nadie canjeo. Aparecen en el cobro de la proxima visita.',
            );
        }
    }

    /**
     * Los premios que el sistema viejo tiene sin canjear, por clienta y hito.
     *
     * @return array<int, array<int, string>> cliente nuevo => [sellos del hito => estado]
     */
    private function premiosVivosDelLegacy(): array
    {
        $filas = $this->legacy('loyalty_card_rewards')
            ->join('loyalty_cards', 'loyalty_cards.id', '=', 'loyalty_card_rewards.loyalty_card_id')
            ->join('loyalty_rewards', 'loyalty_rewards.id', '=', 'loyalty_card_rewards.loyalty_reward_id')
            ->where('loyalty_card_rewards.status', 'available')
            ->get([
                'loyalty_cards.client_id as legacy_client_id',
                'loyalty_rewards.required_stamps as required_stamps',
            ]);

        $mapa = [];

        foreach ($filas as $fila) {
            $clienteNuevo = $this->map->idNuevo('client', $fila->legacy_client_id);

            if ($clienteNuevo === null) {
                continue;
            }

            $mapa[$clienteNuevo][(int) $fila->required_stamps] = LoyaltyReward::STATUS_AVAILABLE;
        }

        return $mapa;
    }

    private function anotar(string $entidad, int $legacyId, int $nuevoId): void
    {
        $this->simular
            ? $this->map->anotarEnMemoria($entidad, $legacyId, $nuevoId)
            : $this->map->anotar($entidad, $legacyId, $nuevoId);
    }
}
