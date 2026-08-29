<?php

namespace App\Models;

use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'business_id', 'name', 'last_name', 'email', 'phone',
        'password', 'is_super_admin', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * El recurso agendable que representa a este usuario, si presta servicios.
     * Un administrador que no atiende clientes simplemente no tiene uno.
     */
    public function resource(): HasOne
    {
        return $this->hasOne(Resource::class);
    }

    public function fullName(): string
    {
        return trim($this->name.' '.$this->last_name);
    }

    /**
     * Permiso granular del catalogo (ver App\Support\PermissionCatalog).
     *
     * Es el unico camino: lo usa el middleware `permission:`, y el mismo
     * conjunto viaja al front en UserResource para armar el menu y los guards
     * del router. Una segunda implementacion en cualquiera de los dos lados
     * es como se termina ofreciendo una opcion que despues rebota con 403.
     */
    public function hasBusinessPermission(string $permission): bool
    {
        // El admin hereda todo por rol; un miembro del equipo necesita el
        // permiso asignado.
        return $this->hasRole(PermissionCatalog::ROLE_ADMIN)
            || $this->hasPermissionTo($permission, 'web');
    }
}
