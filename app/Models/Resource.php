<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cualquier cosa que una cita consume en exclusiva: la profesional, la silla,
 * la cabina, el equipo. Modelar esto como entidad propia -- y no como "usuario
 * empleado" -- es lo que permite que un servicio exija persona Y cabina a la
 * vez, que es imposible de representar cuando la agenda cuelga del usuario.
 */
class Resource extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    public const TYPE_STAFF = 'staff';

    public const TYPE_STATION = 'station';

    public const TYPE_ROOM = 'room';

    public const TYPE_EQUIPMENT = 'equipment';

    /**
     * Sin sede explicita, la principal del negocio.
     *
     * Va en el modelo y no solo en el controlador de alta porque hay varios
     * caminos que crean recursos -- el alta del panel, el seeder, las
     * factories -- y un recurso sin sede desaparece del filtro de la agenda
     * sin que nadie entienda por que. El seeder ya lo hizo una vez.
     *
     * Solo rellena el nulo: una sede elegida a mano nunca se pisa.
     */
    protected static function booted(): void
    {
        static::creating(function (Resource $resource) {
            if ($resource->location_id !== null || $resource->business_id === null) {
                return;
            }

            $resource->location_id = Location::withoutGlobalScopes()
                ->where('business_id', $resource->business_id)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->value('id');
        });
    }

    protected $fillable = [
        'business_id', 'location_id', 'type', 'user_id', 'name', 'color', 'photo_path',
        'bio', 'phone', 'is_public', 'category_rest_days',
        'is_bookable_online', 'is_active', 'sort_order',
        'payroll_mode', 'commission_rate', 'base_amount', 'base_period', 'base_until', 'payroll_started_on',
    ];

    /** Días que tiene que haber entre dos de esta categoría (0 = sin regla). */
    public function restDaysFor(?int $categoryId): int
    {
        if ($categoryId === null) {
            return 0;
        }

        foreach ($this->category_rest_days ?? [] as $rule) {
            if ((int) ($rule['category_id'] ?? 0) === $categoryId) {
                return max(0, (int) ($rule['rest_days'] ?? 0));
            }
        }

        return 0;
    }

    protected function casts(): array
    {
        return [
            'is_bookable_online' => 'boolean',
            'is_active' => 'boolean',
            'category_rest_days' => 'array',
            'commission_rate' => 'decimal:4',
            'base_amount' => 'decimal:2',
            // Fechas y no texto: `base_until` se compara contra el periodo
            // liquidado, y comparar cadenas de fecha es como se cuela un error
            // de un dia.
            'base_until' => 'date',
            'payroll_started_on' => 'date',
        ];
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(PayrollSettlement::class);
    }

    public function payrollAdjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A que numero avisarle de sus citas, o null si no hay a donde.
     *
     * El del recurso manda sobre el de su usuario: la cuenta con la que
     * entra al sistema puede tener el telefono de la casa, y quien atiende
     * suele trabajar con otro. Sin numero, simplemente no se le avisa --
     * pasa todo el tiempo y no es una falla.
     */
    public function notificationPhone(): ?string
    {
        $propio = trim((string) $this->phone);

        if ($propio !== '') {
            return $propio;
        }

        $delUsuario = trim((string) $this->user?->phone);

        return $delUsuario !== '' ? $delUsuario : null;
    }

    /** En que local trabaja. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ResourceSchedule::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_resource')
            ->withPivot(['duration_override_min', 'commission_rate_override']);
    }

    public function appointmentItems(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    public static function types(): array
    {
        return [self::TYPE_STAFF, self::TYPE_STATION, self::TYPE_ROOM, self::TYPE_EQUIPMENT];
    }
}
