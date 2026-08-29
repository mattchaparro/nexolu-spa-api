<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCategory extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = ['business_id', 'name', 'commission_rate', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['commission_rate' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
