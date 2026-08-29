<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un servicio que necesita mas de un tipo de recurso a la vez. Sin esto no se
 * puede expresar que una depilacion ocupa una esteticista y una cabina.
 */
class ServiceResourceRequirement extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['service_id', 'resource_type', 'quantity'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
