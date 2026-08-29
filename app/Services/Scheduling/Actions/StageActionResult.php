<?php

namespace App\Services\Scheduling\Actions;

/**
 * Como le fue a una accion.
 *
 * `skipped` no es un fallo: es la funcion apagada para ese negocio, o la cita
 * sin telefono al que mandar nada. Distinguirlo de `failed` importa -- lo
 * primero es normal, lo segundo hay que ir a mirarlo.
 */
final class StageActionResult
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    private function __construct(
        public readonly string $status,
        public readonly ?string $detail = null,
    ) {}

    public static function ok(?string $detail = null): self
    {
        return new self(self::OK, $detail);
    }

    public static function skipped(string $detail): self
    {
        return new self(self::SKIPPED, $detail);
    }

    public static function failed(string $detail): self
    {
        return new self(self::FAILED, $detail);
    }
}
