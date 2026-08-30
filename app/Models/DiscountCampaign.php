<?php

namespace App\Models;

use App\Support\Money\CampaignCalculator;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Una campana de temporada: el mes de la madre, la semana de pestanas.
 *
 * Se distingue de un combo en quien la decide y por cuanto tiempo. Un combo es
 * un producto permanente del catalogo; una campana es una decision del negocio
 * para traer gente durante unos dias. Por eso su descuento lo absorbe el
 * negocio y no baja la comision de quien atiende.
 */
class DiscountCampaign extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'name', 'description',
        'discount_type', 'discount_value', 'applies_to',
        'starts_on', 'ends_on', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'campaign_services', 'campaign_id', 'service_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceCategory::class, 'campaign_categories', 'campaign_id', 'service_category_id',
        );
    }

    public function runsOn(string $date): bool
    {
        return $this->is_active && CampaignCalculator::runsOn(
            $this->starts_on->toDateString(),
            $this->ends_on?->toDateString(),
            $date,
        );
    }

    /** Si esta campana cubre ese servicio. */
    public function covers(Service $service): bool
    {
        return match ($this->applies_to) {
            CampaignCalculator::APPLIES_ALL => true,
            CampaignCalculator::APPLIES_SERVICES => $this->services
                ->contains(fn (Service $s) => $s->id === $service->id),
            CampaignCalculator::APPLIES_CATEGORIES => $service->service_category_id !== null
                && $this->categories->contains(fn ($c) => $c->id === $service->service_category_id),
            // Un alcance desconocido no cubre nada: al reves, un typo en la
            // configuracion le aplicaria la campana al catalogo entero.
            default => false,
        };
    }

    /** Cuanto descuenta sobre una linea de esa cita. */
    public function discountForPrice(float $price): float
    {
        return CampaignCalculator::discountForPrice(
            $this->discount_type,
            (float) $this->discount_value,
            $price,
        );
    }

    public function label(): string
    {
        return CampaignCalculator::label(
            $this->name,
            $this->discount_type,
            (float) $this->discount_value,
        );
    }
}
