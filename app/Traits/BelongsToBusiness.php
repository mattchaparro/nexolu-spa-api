<?php

namespace App\Traits;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenancy foundation: auto-assigns business_id from the authenticated
 * user on create, and scopes every query to that business by default.
 *
 * Queries made without an authenticated user (console commands, queued
 * jobs, tests without acting as a user) are NOT scoped - use
 * ->withoutGlobalScope('business') explicitly in those contexts, or set
 * business_id manually before creating.
 */
trait BelongsToBusiness
{
    protected static function bootBelongsToBusiness(): void
    {
        static::creating(function ($model) {
            // Autoridad, no sugerencia: con usuario autenticado el business_id
            // SIEMPRE es el suyo, sobrescribiendo cualquier valor que venga del
            // cliente. Cierra la inyeccion de tenant (business_id esta en
            // fillable; un create() con input crudo podria colar otro id). Sin
            // auth (seeders, jobs, tests) se respeta el business_id explicito.
            if (auth()->check() && auth()->user()->business_id) {
                $model->business_id = auth()->user()->business_id;
            }
        });

        static::addGlobalScope('business', function (Builder $query) {
            if (auth()->check() && auth()->user()->business_id) {
                $query->where($query->getModel()->getTable().'.business_id', auth()->user()->business_id);
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
