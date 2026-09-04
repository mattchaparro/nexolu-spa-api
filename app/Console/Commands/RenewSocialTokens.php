<?php

namespace App\Console\Commands;

use App\Models\BusinessSocialAccount;
use App\Services\Social\InstagramTokens;
use Illuminate\Console\Command;

/**
 * Renueva los tokens de Instagram antes de que caduquen.
 *
 * POR QUE EXISTE: un token vencido NO AVISA. Meta los da por sesenta dias, y
 * el dia sesenta y uno las publicaciones dejan de salir en silencio. Nadie
 * revisa una cuenta que "funcionaba", asi que el negocio se entera semanas
 * despues, cuando nota que hace mucho no publica nada.
 *
 * Diario y no mensual: se renueva con siete dias de anticipacion, asi que una
 * corrida perdida tiene seis reintentos antes de que importe. Mensual daria un
 * solo intento y una caducidad silenciosa si ese dia el servidor estaba caido.
 */
class RenewSocialTokens extends Command
{
    protected $signature = 'redes:renovar-tokens';

    protected $description = 'Renueva los tokens de Instagram que están por caducar';

    public function handle(InstagramTokens $tokens): int
    {
        $renovados = 0;
        $vencidos = 0;

        $cuentas = BusinessSocialAccount::withoutGlobalScopes()
            ->where('provider', BusinessSocialAccount::PROVIDER_INSTAGRAM)
            ->with('business')
            ->get();

        foreach ($cuentas as $cuenta) {
            if ($cuenta->hasExpired()) {
                /*
                 * Uno ya vencido no se renueva -- Meta lo rechaza -- y hay que
                 * volver a conectar la cuenta. Se nombra el negocio: es una
                 * tarea para una persona, no un contador.
                 */
                $this->warn("{$cuenta->business?->name}: el token caducó. Hay que reconectar la cuenta.");
                $vencidos++;

                continue;
            }

            if ($tokens->renew($cuenta)) {
                $this->line("{$cuenta->business?->name}: token renovado.");
                $renovados++;
            }
        }

        $this->info("Renovados: {$renovados}. Caducados que hay que reconectar: {$vencidos}.");

        return self::SUCCESS;
    }
}
