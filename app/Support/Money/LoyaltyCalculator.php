<?php

namespace App\Support\Money;

/**
 * La aritmetica de la tarjeta de sellos, sin base de datos.
 *
 * Aparte del servicio que consulta, como el resto del dinero: lo que se rompe
 * en un programa de fidelizacion son los bordes -- un premio que vale mas que
 * la cuenta, una tarjeta configurada en cero sellos, un porcentaje mayor a
 * cien -- y esos se prueban con casos escritos a mano.
 */
final class LoyaltyCalculator
{
    /** Un porcentaje de descuento sobre la cuenta. */
    public const REWARD_DISCOUNT_PERCENT = 'discount_percent';

    /** Un monto fijo de descuento. */
    public const REWARD_DISCOUNT_AMOUNT = 'discount_amount';

    /** Un servicio del catalogo, gratis. */
    public const REWARD_FREE_SERVICE = 'free_service';

    /** @return list<string> */
    public static function rewardTypes(): array
    {
        return [
            self::REWARD_DISCOUNT_PERCENT,
            self::REWARD_DISCOUNT_AMOUNT,
            self::REWARD_FREE_SERVICE,
        ];
    }

    /**
     * Como va la tarjeta.
     *
     * @return array{stamps: int, required: int, remaining: int, complete: bool}
     */
    public static function progress(int $stamps, int $required): array
    {
        $stamps = max(0, $stamps);

        /*
         * Un programa mal configurado en 0 sellos daria un premio en cada
         * visita, para siempre. Se trata como "sin programa" en vez de
         * regalar el catalogo: el error se nota porque nadie gana nada, no
         * porque el negocio pierda plata.
         */
        if ($required < 1) {
            return ['stamps' => $stamps, 'required' => 0, 'remaining' => 0, 'complete' => false];
        }

        return [
            'stamps' => $stamps,
            'required' => $required,
            'remaining' => max(0, $required - $stamps),
            'complete' => $stamps >= $required,
        ];
    }

    /**
     * Cuantas tarjetas COMPLETAS hay en ese saldo.
     *
     * Devuelve mas de una a proposito: si alguien acumulo doce sellos con una
     * tarjeta de cinco, se ganó dos premios y le quedan dos sellos. Entregar
     * uno solo y tirar el resto seria quedarse con sellos que la persona ya
     * se gano.
     */
    public static function completedCards(int $stamps, int $required): int
    {
        if ($required < 1 || $stamps < $required) {
            return 0;
        }

        return intdiv($stamps, $required);
    }

    /**
     * Cuanto descuenta un premio sobre una cuenta.
     *
     * Nunca mas que la cuenta: un premio de 50.000 sobre una visita de 30.000
     * no puede dejar al negocio devolviendo plata. Lo que sobra se pierde, que
     * es como funciona un bono en el mostrador.
     */
    public static function discountFor(string $type, ?float $value, float $ticketTotal): float
    {
        if ($ticketTotal <= 0 || $value === null || $value <= 0) {
            return 0.0;
        }

        $amount = match ($type) {
            self::REWARD_DISCOUNT_PERCENT => $ticketTotal * (self::clampPercent($value) / 100),
            self::REWARD_DISCOUNT_AMOUNT => $value,
            // El servicio gratis no se resuelve aca: depende del precio que
            // tenga ESA linea en ESA cita, y eso lo sabe el checkout.
            default => 0.0,
        };

        return round(min($amount, $ticketTotal), 2);
    }

    public static function clampPercent(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }

    /** Como se le explica el premio a quien lo va a recibir. */
    public static function label(string $type, ?float $value, ?string $serviceName = null): string
    {
        return match ($type) {
            self::REWARD_DISCOUNT_PERCENT => rtrim(rtrim(number_format(self::clampPercent((float) $value), 2, ',', '.'), '0'), ',').'% de descuento',
            self::REWARD_DISCOUNT_AMOUNT => '$'.number_format((float) $value, 0, ',', '.').' de descuento',
            self::REWARD_FREE_SERVICE => ($serviceName ?? 'Un servicio').' gratis',
            default => 'Premio',
        };
    }

    /**
     * Si esa visita da sello.
     *
     * El minimo existe porque en el sistema viejo un retoque barato daba el
     * mismo sello que un juego completo: la tarjeta se llenaba con lo barato y
     * el premio salia del margen de lo caro.
     */
    public static function earnsStamp(float $ticketTotal, float $minTicket): bool
    {
        return $ticketTotal > 0 && $ticketTotal >= $minTicket;
    }
}
