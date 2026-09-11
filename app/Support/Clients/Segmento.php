<?php

namespace App\Support\Clients;

/**
 * En que punto de su relacion con el local esta una clienta.
 *
 * Calculado, NO escrito a mano. Una etiqueta manual sobre 759 fichas se pone
 * una vez y queda vieja al mes siguiente: nadie vuelve a marcar "frecuente" a
 * quien dejo de venir. Los datos ya dicen cuantas veces vino y cuando fue la
 * ultima, asi que la etiqueta se deduce y nunca miente.
 *
 * LOS UMBRALES SALEN DE LOS DATOS DE LUXURY, no de un manual. Asi se reparten
 * sus 759 fichas por visitas cobradas:
 *
 *     0 visitas   357      5 a 9       50
 *     1 visita    197      10 o mas    24
 *     2 a 4       134      (el techo son 22 visitas, dos clientas)
 *
 * Por eso "frecuente" arranca en 5: deja un grupo de ~74 personas, chico y de
 * verdad fiel. Ponerlo en 3 metia a 134 y la etiqueta dejaba de distinguir.
 *
 * Y asi se reparten por cuando vinieron por ultima vez:
 *
 *     ultimo mes   15      6 a 12 meses   124
 *     1 a 3 meses  39      mas de un año  167
 *     3 a 6 meses  57      nunca          357
 *
 * De ahi los 90 dias para "se enfrio": a los tres meses ya paso el ciclo
 * normal de un semipermanente dos o tres veces, asi que quien no volvio no es
 * que "todavia no le toca".
 */
final class Segmento
{
    public const FRECUENTE = 'frecuente';

    public const OCASIONAL = 'ocasional';

    public const NUEVA = 'nueva';

    public const SIN_VISITAS = 'sin_visitas';

    /** Desde cuantas visitas cobradas se considera frecuente. */
    public const VISITAS_FRECUENTE = 5;

    /** A partir de cuantos dias sin venir se considera que se enfrio. */
    public const DIAS_ENFRIADA = 90;

    /** A partir de cuantos dias se da por perdida. */
    public const DIAS_PERDIDA = 365;

    /**
     * Cuantas veces vino. Nada de fechas.
     */
    public static function porVisitas(int $visitas): string
    {
        if ($visitas >= self::VISITAS_FRECUENTE) {
            return self::FRECUENTE;
        }

        if ($visitas >= 2) {
            return self::OCASIONAL;
        }

        return $visitas === 1 ? self::NUEVA : self::SIN_VISITAS;
    }

    /**
     * Como se dice en el mostrador.
     */
    public static function etiqueta(string $segmento): string
    {
        return match ($segmento) {
            self::FRECUENTE => 'Frecuente',
            self::OCASIONAL => 'Ocasional',
            self::NUEVA => 'Vino una vez',
            default => 'Sin visitas',
        };
    }

    /** @return list<string> */
    public static function todos(): array
    {
        return [self::FRECUENTE, self::OCASIONAL, self::NUEVA, self::SIN_VISITAS];
    }
}
