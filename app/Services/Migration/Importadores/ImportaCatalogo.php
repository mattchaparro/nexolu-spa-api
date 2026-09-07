<?php

namespace App\Services\Migration\Importadores;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Services\Migration\LegacyMap;
use App\Support\Money\PackagePricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Categorias, servicios y combos.
 *
 * QUE SE ACTUALIZA EN LAS CORRIDAS SIGUIENTES, y por que:
 *
 *   - PRECIO y ACTIVO si. Mientras los dos sistemas conviven, el negocio
 *     administra su carta en el viejo. Si manana sube el semipermanente y
 *     aca sigue el precio de ayer, el primer cobro que se haga en el sistema
 *     nuevo sale mal.
 *
 *   - DURACION no, nunca. Las duraciones del legacy estan sistematicamente
 *     cortas (el semipermanente dice 60 min y toma 115 de verdad) porque alla
 *     la rejilla era de bloques fijos de 120 y absorbia el error. Aca el
 *     motor respeta la duracion al minuto. La migracion es justo la ocasion
 *     de corregirlas, y reimportarlas cada noche desharia esa correccion sin
 *     que nadie se entere hasta que la agenda se atrase.
 *
 *   - NOMBRE y DESCRIPCION tampoco: alguien pudo mejorarlos aca.
 */
class ImportaCatalogo extends Importador
{
    /**
     * Los cuatro combos, y de que servicios se arman.
     *
     * En el legacy son servicios sueltos con su propio precio. Aca son
     * paquetes de servicios que YA EXISTEN, porque su precio es la suma
     * exacta de sus partes -- verificado uno por uno contra la base:
     * 20.000 + 30.000 = 50.000, y asi los cuatro. Duplicarlos como servicios
     * independientes dejaria al negocio con dos "Semipermanente" distintos
     * que reportan por separado.
     *
     * Van por id del legacy y no por nombre: los nombres se editan.
     *
     * @var array<int, list<int>> id del combo => ids de sus partes, en orden
     */
    public const COMBOS = [
        39 => [3, 17],  // Semi Manos + Pies       = Semipermanente + Pedi Jelly Semi
        40 => [5, 16],  // Tradi Manos + Pies      = Tradicional + Pedi Jellyspa
        41 => [3, 16],  // Manos Semi + Pies Tradi = Semipermanente + Pedi Jellyspa
        45 => [4, 17],  // Semi Rubber + Semi Pies = Semi+Rubber + Pedi Jelly Semi
    ];

    public function nombre(): string
    {
        return 'Catalogo';
    }

    public function correr(): void
    {
        $this->categorias();
        $this->servicios();
        $this->combos();
    }

    private function categorias(): void
    {
        $filas = $this->legacy('service_types')->whereNull('deleted_at')->orderBy('id')->get();

        foreach ($filas as $i => $fila) {
            if ($this->map->yaExiste('service_category', $fila->id)) {
                $this->reporte->saltado('Categorias');

                continue;
            }

            $id = $this->crear(fn () => ServiceCategory::create([
                'business_id' => $this->business->id,
                'name' => $fila->name,
                'sort_order' => $i,
                'is_active' => true,
            ])->id);

            $this->anotar('service_category', (int) $fila->id, $id);
            $this->reporte->creado('Categorias');
        }
    }

    private function servicios(): void
    {
        $filas = $this->legacy('services')
            ->whereNull('deleted_at')
            ->whereNotIn('id', array_keys(self::COMBOS))
            ->orderBy('id')
            ->get();

        foreach ($filas as $fila) {
            $precio = (float) $fila->price;
            $activo = (bool) $fila->active;
            $huella = LegacyMap::huella([$precio, $activo]);

            $idNuevo = $this->map->idNuevo('service', $fila->id);

            if ($idNuevo !== null) {
                $this->sincronizarServicio((int) $fila->id, $idNuevo, $precio, $activo, $huella);

                continue;
            }

            $id = $this->crear(fn () => Service::create([
                'business_id' => $this->business->id,
                'name' => $fila->name,
                'slug' => $this->slug($fila->name),
                'description' => $fila->description ?: null,
                'service_category_id' => $this->map->idNuevo('service_category', $fila->service_type_id),
                'duration_min' => max(5, (int) $fila->duration),
                'price' => $precio,
                'is_active' => $activo,
                'is_bookable_online' => $activo,
            ])->id);

            $this->anotar('service', (int) $fila->id, $id, $huella);
            $this->reporte->creado('Servicios');
        }
    }

    /** Precio y activo al dia; lo demas se respeta como este aca. */
    private function sincronizarServicio(int $legacyId, int $idNuevo, float $precio, bool $activo, string $huella): void
    {
        if (! $this->map->cambio('service', $legacyId, $huella)) {
            $this->reporte->saltado('Servicios');

            return;
        }

        if (! $this->simular) {
            Service::withoutGlobalScope('business')
                ->where('id', $idNuevo)
                ->update(['price' => $precio, 'is_active' => $activo]);
        }

        $this->anotar('service', $legacyId, $idNuevo, $huella);
        $this->reporte->actualizado('Servicios');
    }

    private function combos(): void
    {
        foreach (self::COMBOS as $legacyId => $partesLegacy) {
            if ($this->map->yaExiste('service_package', $legacyId)) {
                $this->reporte->saltado('Combos');

                continue;
            }

            $fila = $this->legacy('services')->where('id', $legacyId)->first();

            if (! $fila) {
                $this->reporte->aviso('Combos', "El combo {$legacyId} ya no esta en el legacy.");

                continue;
            }

            $partes = [];

            foreach ($partesLegacy as $parteLegacy) {
                $id = $this->map->idNuevo('service', $parteLegacy);

                if ($id === null) {
                    $this->reporte->aviso(
                        'Combos',
                        "{$fila->name} necesita el servicio {$parteLegacy}, que no se importo.",
                    );

                    continue 2;
                }

                $partes[] = $id;
            }

            /*
             * Sin descuento: los cuatro combos de Luxury valen exactamente la
             * suma de sus partes. Ponerles un descuento inventado cambiaria
             * lo que la clienta paga.
             */
            // Paquete y partes juntos: un paquete sin partes no tiene ni
            // precio ni duracion, y se agenda como si durara cero minutos.
            $id = $this->crear(fn () => DB::transaction(function () use ($fila, $partes) {
                $paquete = ServicePackage::create([
                    'business_id' => $this->business->id,
                    'name' => $fila->name,
                    'slug' => $this->slug($fila->name),
                    // `none`, no null: la columna no admite nulo, y "sin
                    // descuento" es un tipo de regla, no la ausencia de una.
                    'discount_type' => PackagePricing::TYPE_NONE,
                    'discount_value' => null,
                    'is_active' => (bool) $fila->active,
                    'is_bookable_online' => (bool) $fila->active,
                ]);

                foreach ($partes as $orden => $servicioId) {
                    DB::table('service_package_items')->insert([
                        'package_id' => $paquete->id,
                        'service_id' => $servicioId,
                        'sort_order' => $orden,
                    ]);
                }

                return $paquete->id;
            }));

            $this->anotar('service_package', (int) $legacyId, $id);
            $this->reporte->creado('Combos');

            /*
             * La duracion del paquete es la suma de sus partes, y en el legacy
             * el combo bloqueaba mas tiempo que esa suma. No se corrige a
             * escondidas: se avisa, porque el numero correcto lo sabe el
             * negocio y no la migracion.
             */
            $suma = (int) Service::withoutGlobalScope('business')
                ->whereIn('id', $partes)->sum('duration_min');

            if (! $this->simular && $suma > 0 && (int) $fila->duration > $suma) {
                $this->reporte->aviso(
                    'Combos',
                    "{$fila->name} bloqueaba {$fila->duration} min en el sistema viejo y sus "
                    ."partes suman {$suma}. Revisar las duraciones de los servicios.",
                );
            }
        }
    }

    private function slug(string $nombre): string
    {
        return Str::slug($nombre).'-'.Str::lower(Str::random(4));
    }

    private function anotar(string $entidad, int $legacyId, int $nuevoId, ?string $huella = null): void
    {
        $this->simular
            ? $this->map->anotarEnMemoria($entidad, $legacyId, $nuevoId, $huella)
            : $this->map->anotar($entidad, $legacyId, $nuevoId, $huella);
    }
}
