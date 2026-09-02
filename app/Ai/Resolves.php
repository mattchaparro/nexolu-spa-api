<?php

namespace App\Ai;

use App\Models\Location;
use App\Models\Resource;
use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Traducir del vocabulario de una conversacion al de la base de datos.
 *
 * El modelo habla en NOMBRES -- "manicure semipermanente", "con Maria", "en
 * Cedritos" -- porque es lo que dijo la clienta. Aca se convierten en filas.
 *
 * Cuando el nombre es ambiguo NO se adivina: se devuelve un error que lista
 * las opciones, para que el agente pregunte. Agendar "lo que mas se parece"
 * es como se termina cobrando 180.000 de acrilicas a quien pidio un retoque.
 */
trait Resolves
{
    /** @throws AiArgumentException */
    protected function resolveService(int $businessId, string $nombre): Service
    {
        $servicios = Service::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->get();

        return $this->pickByName($servicios, $nombre, 'servicio');
    }

    /** @throws AiArgumentException */
    protected function resolveResource(int $businessId, string $nombre, ?int $locationId = null): Resource
    {
        $recursos = Resource::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->where('type', Resource::TYPE_STAFF)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->get();

        return $this->pickByName($recursos, $nombre, 'persona');
    }

    /** @throws AiArgumentException */
    protected function resolveLocation(int $businessId, ?string $nombre): ?Location
    {
        $sedes = Location::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->get();

        if ($sedes->count() <= 1) {
            return $sedes->first();
        }

        /*
         * Con varias sedes la sede es OBLIGATORIA y no se adivina: cada local
         * tiene su propia gente y su propio horario, y ofrecerle a alguien una
         * hora en la sede equivocada es hacerle cruzar la ciudad.
         */
        if ($nombre === null || trim($nombre) === '') {
            throw new AiArgumentException(
                'Este negocio tiene varias sedes. Pregúntale a la clienta a cuál va: '
                .$sedes->pluck('name')->implode(', ').'.'
            );
        }

        return $this->pickByName($sedes, $nombre, 'sede');
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, T>  $filas
     * @return T
     *
     * @throws AiArgumentException
     */
    private function pickByName(Collection $filas, string $nombre, string $que): mixed
    {
        $buscado = $this->normalize($nombre);

        if ($buscado === '') {
            throw new AiArgumentException("Falta decir qué {$que}.");
        }

        $exactos = $filas->filter(fn ($f) => $this->normalize($f->name) === $buscado);

        if ($exactos->count() === 1) {
            return $exactos->first();
        }

        // Sin coincidencia exacta, se acepta que lo dicho este CONTENIDO en el
        // nombre real ("semipermanente" -> "Manicure semipermanente").
        $parciales = $exactos->isEmpty()
            ? $filas->filter(fn ($f) => str_contains($this->normalize($f->name), $buscado))
            : $exactos;

        if ($parciales->count() === 1) {
            return $parciales->first();
        }

        if ($parciales->isEmpty()) {
            throw new AiArgumentException(
                "No existe el {$que} «{$nombre}». Los que hay: ".$filas->pluck('name')->implode(', ').'.'
            );
        }

        throw new AiArgumentException(
            "«{$nombre}» puede ser varias cosas: ".$parciales->pluck('name')->implode(', ')
            .'. Pregúntale a la clienta cuál.'
        );
    }

    /** Sin tildes, sin mayusculas y sin espacios de sobra: la gente escribe como escribe. */
    private function normalize(string $texto): string
    {
        $sinTildes = strtr(
            mb_strtolower(trim($texto)),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'],
        );

        return preg_replace('/\s+/', ' ', $sinTildes) ?? '';
    }
}
