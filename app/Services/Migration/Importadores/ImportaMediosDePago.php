<?php

namespace App\Services\Migration\Importadores;

use App\Models\PaymentMethod;
use App\Models\PlatformPaymentMethod;

/**
 * Los medios de pago del local.
 *
 * Corre antes del historial porque cada atencion cobrada dice con que se
 * pago, y un cobro sin medio de pago no cuadra la caja.
 *
 * Se reusa el medio que ya exista aca con el mismo nombre en vez de crear
 * otro: si el negocio ya configuro "Nequi" en el sistema nuevo, la migracion
 * no tiene por que dejarle dos Nequi en el selector del checkout.
 */
class ImportaMediosDePago extends Importador
{
    public function nombre(): string
    {
        return 'Medios de pago';
    }

    public function correr(): void
    {
        $filas = $this->legacy('payment_methods')->whereNull('deleted_at')->orderBy('id')->get();

        foreach ($filas as $orden => $fila) {
            if ($this->map->yaExiste('payment_method', $fila->id)) {
                $this->reporte->saltado('Medios de pago');

                continue;
            }

            $existente = $this->simular ? null : PaymentMethod::withoutGlobalScope('business')
                ->where('business_id', $this->business->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($fila->name))])
                ->value('id');

            if ($existente !== null) {
                $this->anotar('payment_method', (int) $fila->id, (int) $existente);
                $this->reporte->saltado('Medios de pago');

                continue;
            }

            $id = $this->crear(fn () => PaymentMethod::create([
                'business_id' => $this->business->id,
                'platform_payment_method_id' => $this->deLaPlataforma($fila->name),
                'name' => $fila->name,
                'counts_as_cash' => (bool) $fila->counts_as_cash,
                'is_active' => (bool) $fila->is_active,
                'sort_order' => $orden,
            ])->id);

            $this->anotar('payment_method', (int) $fila->id, $id);
            $this->reporte->creado('Medios de pago');
        }
    }

    /**
     * El medio equivalente del catalogo de la plataforma, si lo hay.
     *
     * Vincularlo no es cosmetico: es lo que permite que un dia la plataforma
     * sepa que el "Nequi" de Luxury y el de otro local son el mismo medio.
     * Si no hay equivalente, el medio vive suelto y funciona igual.
     */
    private function deLaPlataforma(string $nombre): ?int
    {
        // El catalogo de plataforma no tiene `name`: tiene `key` (nequi,
        // efectivo) y `label` (lo que se muestra). Se busca por las dos.
        $buscado = mb_strtolower(trim($nombre));

        return PlatformPaymentMethod::query()
            ->where(fn ($q) => $q
                ->whereRaw('LOWER(`key`) = ?', [$buscado])
                ->orWhereRaw('LOWER(label) = ?', [$buscado]))
            ->value('id');
    }

    private function anotar(string $entidad, int $legacyId, int $nuevoId): void
    {
        $this->simular
            ? $this->map->anotarEnMemoria($entidad, $legacyId, $nuevoId)
            : $this->map->anotar($entidad, $legacyId, $nuevoId);
    }
}
