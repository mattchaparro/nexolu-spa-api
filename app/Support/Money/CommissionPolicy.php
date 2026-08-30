<?php

namespace App\Support\Money;

/**
 * Sobre que valor se le paga comision a quien atendio cuando hubo descuento.
 *
 * La pregunta que resuelve: si a una clienta le regalan el servicio por su
 * tarjeta de sellos, ¿la profesional trabaja gratis?
 *
 * No hay una respuesta correcta para todos los negocios, y por eso es
 * configurable POR ORIGEN del descuento. Quien decidio el descuento suele ser
 * quien deberia asumirlo:
 *
 *   - Un premio de fidelizacion lo regala EL NEGOCIO para que la clienta
 *     vuelva. El trabajo fue el mismo, asi que por defecto la comision se
 *     paga sobre el precio de lista.
 *   - Un combo es un precio promocional que el negocio publico; por defecto
 *     la comision va sobre lo que de verdad entro.
 *   - Un descuento escrito a mano en el mostrador tambien va sobre lo
 *     cobrado, que es el comportamiento que el sistema ya tenia.
 *
 * Los defaults de combo y manual conservan lo que ya hacia el sistema: nadie
 * se despierta con la nomina cambiada por un deploy.
 */
final class CommissionPolicy
{
    /** La comision se calcula sobre lo que se cobro, con el descuento aplicado. */
    public const BASE_CHARGED = 'charged';

    /** La comision se calcula sobre el precio de lista, ignorando el descuento. */
    public const BASE_LIST = 'list';

    /** Descuento escrito a mano al cobrar. */
    public const SOURCE_MANUAL = 'manual';

    /** El descuento que trae un combo. */
    public const SOURCE_PACKAGE = 'package';

    /** Un premio de la tarjeta de sellos. */
    public const SOURCE_LOYALTY = 'loyalty';

    /** Una campana de temporada: el mes de la madre, la semana de pestanas. */
    public const SOURCE_CAMPAIGN = 'campaign';

    /** @return list<string> */
    public static function sources(): array
    {
        return [
            self::SOURCE_MANUAL,
            self::SOURCE_PACKAGE,
            self::SOURCE_LOYALTY,
            self::SOURCE_CAMPAIGN,
        ];
    }

    /** @return list<string> */
    public static function bases(): array
    {
        return [self::BASE_CHARGED, self::BASE_LIST];
    }

    /**
     * Como se le explica cada origen a quien configura el negocio.
     *
     * @return array<string, array{label: string, help: string}>
     */
    public static function labels(): array
    {
        return [
            self::SOURCE_MANUAL => [
                'label' => 'Descuento hecho a mano',
                'help' => 'El que alguien escribe al cobrar, por un acuerdo puntual con el cliente.',
            ],
            self::SOURCE_PACKAGE => [
                'label' => 'Descuento de un combo',
                'help' => 'El precio promocional que el negocio publicó al armar el combo.',
            ],
            self::SOURCE_LOYALTY => [
                'label' => 'Premio de la tarjeta de sellos',
                'help' => 'Una atención al cliente por su fidelidad. De esa fidelidad vive '
                    .'también quien lo atiende: un cliente que vuelve es trabajo suyo.',
            ],
            self::SOURCE_CAMPAIGN => [
                'label' => 'Campaña de temporada',
                'help' => 'El mes de la madre, la semana de pestañas. La decide el negocio para '
                    .'traer gente nueva, así que normalmente la asume el negocio.',
            ],
        ];
    }

    /**
     * @return array<string, array{value: string, label: string, help: string}>
     */
    public static function baseLabels(): array
    {
        return [
            self::BASE_CHARGED => [
                'value' => self::BASE_CHARGED,
                'label' => 'Sobre lo cobrado',
                'help' => 'El descuento también le baja la comisión a quien atendió.',
            ],
            self::BASE_LIST => [
                'value' => self::BASE_LIST,
                'label' => 'Sobre el precio de lista',
                'help' => 'Quien atendió cobra igual; el descuento lo asume el negocio.',
            ],
        ];
    }

    /** La llave de configuracion de un origen. */
    public static function settingKey(string $source): string
    {
        return "commission_base_{$source}";
    }

    /**
     * Cuanto de un descuento le baja la comision a quien atendio.
     *
     * Recibe cuanto puso cada origen y sobre que se paga cada uno, y suma solo
     * lo que corresponde. Un cobro puede mezclar origenes -- un combo con un
     * premio encima -- y cada parte se trata por su cuenta.
     *
     * @param  array<string, float>  $bySource  Origen => monto descontado.
     * @param  array<string, string>  $bases  Origen => BASE_CHARGED|BASE_LIST.
     */
    public static function discountAffectingCommission(array $bySource, array $bases): float
    {
        $total = 0.0;

        foreach ($bySource as $source => $amount) {
            if ($amount <= 0) {
                continue;
            }

            /*
             * Un origen sin configurar baja la comision. Es el comportamiento
             * que el sistema ya tenia, y el conservador: al reves, un origen
             * nuevo que nadie configuro pagaria comision sobre plata que no
             * entro, y eso se descubre en la nomina.
             */
            if (($bases[$source] ?? self::BASE_CHARGED) === self::BASE_CHARGED) {
                $total += $amount;
            }
        }

        return round($total, 2);
    }
}
