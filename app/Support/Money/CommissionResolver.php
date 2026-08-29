<?php

namespace App\Support\Money;

/**
 * Que porcentaje de comision le toca a una persona por un servicio.
 *
 * Sin base de datos: recibe los cuatro porcentajes que pueden aplicar y decide
 * cual gana. Vive aparte porque es la pregunta que mas se hace y la que peor
 * se responde de memoria -- "¿por que a Ana le liquidaron 30% si ella va al
 * 50%?" -- y porque contestarla mal significa pagarle de menos a alguien.
 *
 * La cascada, de lo mas especifico a lo mas general:
 *
 *   1. ACUERDO PUNTUAL   Esta persona, en este servicio. Lo que se pacta
 *                        cuando alguien es la unica que hace algo, o cuando
 *                        requiere una habilidad que el resto no tiene.
 *   2. LA PERSONA        Su porcentaje general. "Ana va al 50% en todo".
 *   3. EL SERVICIO       El porcentaje propio del servicio.
 *   4. LA CATEGORIA      El de su familia de servicios. "Todo lo de pestañas
 *                        paga 40%".
 *   5. NADA              Sin comision.
 *
 * Que la PERSONA gane sobre el SERVICIO es la decision que importa, y es
 * deliberada: el porcentaje de alguien es parte de su acuerdo laboral, y un
 * servicio nuevo que entre al catalogo con otro numero no puede cambiarle en
 * silencio lo que gana. Al reves -- servicio sobre persona -- el dia que
 * alguien crea "Manicure express 20%", todo el equipo pasa a ganar 20% en ese
 * servicio sin que nadie lo haya acordado.
 *
 * Devuelve tambien DE DONDE salio el numero. Sin eso, la pantalla solo puede
 * mostrar "30%" y quien pregunta por que se queda sin respuesta.
 */
final class CommissionResolver
{
    public const SOURCE_AGREEMENT = 'agreement';

    public const SOURCE_PERSON = 'person';

    public const SOURCE_SERVICE = 'service';

    public const SOURCE_CATEGORY = 'category';

    public const SOURCE_NONE = 'none';

    /**
     * @param  float|null  $agreement  Acuerdo puntual persona+servicio.
     * @param  float|null  $person     Porcentaje general de la persona.
     * @param  float|null  $service    Porcentaje del servicio.
     * @param  float|null  $category   Porcentaje de la categoria del servicio.
     * @return array{rate: float|null, source: string}
     */
    public static function resolve(
        ?float $agreement = null,
        ?float $person = null,
        ?float $service = null,
        ?float $category = null,
    ): array {
        foreach ([
            [self::SOURCE_AGREEMENT, $agreement],
            [self::SOURCE_PERSON, $person],
            [self::SOURCE_SERVICE, $service],
            [self::SOURCE_CATEGORY, $category],
        ] as [$source, $rate]) {
            if ($rate !== null) {
                return ['rate' => self::clamp($rate), 'source' => $source];
            }
        }

        return ['rate' => null, 'source' => self::SOURCE_NONE];
    }

    public static function label(string $source): string
    {
        return match ($source) {
            self::SOURCE_AGREEMENT => 'Acuerdo para este servicio',
            self::SOURCE_PERSON => 'Porcentaje de la persona',
            self::SOURCE_SERVICE => 'Porcentaje del servicio',
            self::SOURCE_CATEGORY => 'Porcentaje de la categoría',
            default => 'Sin comisión',
        };
    }

    /**
     * Entre 0 y 1.
     *
     * Un 1.5 guardado por error -- alguien escribio 150 en un campo que espera
     * porcentaje -- pagaria mas comision que el precio del servicio. Se corta
     * aca y no en la pantalla, porque el dato puede entrar por la API, por una
     * carga masiva o por el seeder.
     */
    private static function clamp(float $rate): float
    {
        return max(0.0, min(1.0, $rate));
    }
}
