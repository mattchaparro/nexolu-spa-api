<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Location;
use App\Models\Resource;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Darle al negocio local el catálogo REAL de producción.
 *
 * Las clientas simuladas están escritas contra el salón de verdad ("quiero
 * un Tradicional", "semi con rubber") y el sembrado local traía un catálogo
 * demo con otros nombres y dos sedes: cada corrida local llenaba el reporte
 * de falsos hallazgos ("quedó agendado Manicure clasico y se esperaba
 * Tradicional") que no le pasan a nadie en producción.
 *
 * Se copia de la API PÚBLICA de reservas -- la misma que ve cualquier
 * persona en la página -- así que no necesita credenciales y trae exactamente
 * lo que el bot puede ofrecer (lo oculto no viaja). Las profesionales
 * locales se quedan: solo se les cuelgan los servicios del espejo.
 */
class EspejoDeProduccion extends Command
{
    protected $signature = 'ia:espejo
        {business=1 : El negocio local a reformar}
        {--desde=https://agenda-backend.nexolu.co/api/v1/public/luxury-nails : La página pública que se copia}';

    protected $description = 'Copia el catálogo público de producción al negocio local (para simular con lo real)';

    public function handle(): int
    {
        $business = Business::findOrFail((int) $this->argument('business'));
        $desde = rtrim((string) $this->option('desde'), '/');

        try {
            $pagina = Http::timeout(30)->get($desde)->throw()->json();
            $servicios = Http::timeout(30)->get($desde.'/services')->throw()->json();
        } catch (ConnectionException $e) {
            if (! str_contains($e->getMessage(), 'SSL certificate')) {
                throw $e;
            }

            /*
             * El PHP de Windows suele venir sin `curl.cainfo`. Esto lee un
             * catalogo PUBLICO en un comando de desarrollo: mejor avisar y
             * seguir que pedirle a cada quien que configure su php.ini.
             */
            $this->warn('El PHP local no puede verificar el certificado; se lee sin verificar (catálogo público).');
            $pagina = Http::withoutVerifying()->timeout(30)->get($desde)->throw()->json();
            $servicios = Http::withoutVerifying()->timeout(30)->get($desde.'/services')->throw()->json();
        }

        if (! is_array($servicios) || $servicios === []) {
            $this->error('La página pública no devolvió servicios.');

            return self::FAILURE;
        }

        $this->sedesComoAlla($business, $pagina['locations'] ?? []);
        $this->catalogoComoAlla($business, $servicios);

        $this->info(sprintf(
            'Listo: %d servicios activos en %d categorías, %d sede(s). Como en producción.',
            Service::withoutGlobalScope('business')->where('business_id', $business->id)->where('is_active', true)->count(),
            ServiceCategory::withoutGlobalScope('business')->where('business_id', $business->id)->where('is_active', true)->count(),
            Location::withoutGlobalScope('business')->where('business_id', $business->id)->where('is_active', true)->count(),
        ));

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $sedes */
    private function sedesComoAlla(Business $business, array $sedes): void
    {
        $nombres = array_map(fn ($s) => (string) $s['name'], $sedes);

        if ($nombres === []) {
            return;
        }

        // Se quedan las sedes con más equipo: apagar la de tres
        // profesionales para conservar la de una deja la agenda coja.
        $locales = Location::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->withCount(['resources as staff' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('staff')
            ->get();

        foreach ($locales as $i => $sede) {
            // La sede local con más gente toma el nombre de la primera
            // real; las que sobran se apagan (producción tiene una sola).
            if ($i < count($nombres)) {
                $sede->update(['name' => $nombres[$i], 'is_active' => true]);
            } else {
                $sede->update(['is_active' => false]);
            }
        }
    }

    /** @param list<array<string, mixed>> $servicios */
    private function catalogoComoAlla(Business $business, array $servicios): void
    {
        $manos = Resource::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('type', Resource::TYPE_STAFF)
            ->where('is_active', true)
            ->pluck('id');

        $categorias = [];
        $vigentes = [];

        foreach ($servicios as $s) {
            $nombreCategoria = trim((string) ($s['category'] ?? ''));
            $categoriaId = null;

            if ($nombreCategoria !== '') {
                $categorias[$nombreCategoria] ??= ServiceCategory::withoutGlobalScope('business')->firstOrCreate(
                    ['business_id' => $business->id, 'name' => $nombreCategoria],
                    ['is_active' => true],
                )->id;
                $categoriaId = $categorias[$nombreCategoria];
            }

            $servicio = Service::withoutGlobalScope('business')->updateOrCreate(
                ['business_id' => $business->id, 'name' => (string) $s['name']],
                [
                    'slug' => str((string) $s['name'])->slug()->value(),
                    'description' => $s['description'] ?? null,
                    'duration_min' => (int) ($s['duration_min'] ?? 60),
                    'price' => (float) ($s['price'] ?? 0),
                    'service_category_id' => $categoriaId,
                    'is_active' => true,
                    'is_bookable_online' => true,
                ],
            );

            // Aca las profesionales son las locales: todas saben de todo.
            $servicio->resources()->sync($manos);
            $vigentes[] = $servicio->id;
        }

        // Lo que produccion no ofrece, aca tampoco.
        Service::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->whereNotIn('id', $vigentes)
            ->update(['is_active' => false]);
    }
}
