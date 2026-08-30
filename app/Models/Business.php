<?php

namespace App\Models;

use App\Support\BusinessFeaturePresets;
use App\Support\Money\DepositCalculator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'vertical', 'timezone', 'country_code', 'currency',
        'phone', 'email', 'address', 'logo_path', 'cover_path',
        'public_profile', 'feature_flags', 'subscription_plan', 'scheduling_settings', 'is_active',
        'appointment_workflow_id',
    ];

    protected function casts(): array
    {
        return [
            'feature_flags' => 'array',
            'public_profile' => 'array',
            'scheduling_settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** El flujo de etapas que este negocio usa para sus citas. */
    public function appointmentWorkflow(): BelongsTo
    {
        return $this->belongsTo(AppointmentWorkflow::class, 'appointment_workflow_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Ajuste de agenda del negocio, con el default de plataforma como respaldo.
     *
     * Es el UNICO camino para leer estos valores. Ningun Service debe llamar
     * config('spa.defaults') directo: eso volveria a convertir una politica de
     * negocio en una constante de aplicacion, que es justo el error que
     * heredamos de Blue Souls.
     */
    public function schedulingSetting(string $key): mixed
    {
        return data_get($this->scheduling_settings, $key)
            ?? config("spa.defaults.{$key}");
    }

    public function businessTimezone(): string
    {
        return $this->timezone ?: config('spa.defaults.timezone');
    }

    /**
     * Feature flags ya resueltos. El front lee esto y nunca reimplementa las
     * ramas -- desincronizar las dos copias es un bug real que el POS ya tuvo.
     *
     * @return array<string, bool>
     */
    public function resolvedFeatureFlags(): array
    {
        $defaults = BusinessFeaturePresets::fromPlan($this->subscription_plan);
        $explicit = $this->feature_flags;

        // Negocio antiguo sin flags: todo habilitado.
        if (empty($explicit)) {
            return $defaults;
        }

        return array_merge($defaults, $explicit);
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) ($this->resolvedFeatureFlags()[$feature] ?? false);
    }

    /**
     * La politica de abono del negocio, o null si no pide.
     *
     * Devuelve null tambien con la bandera apagada, para que ningun camino
     * pueda calcular un abono de un negocio que no contrato la funcion: la
     * comprobacion vive aca y no repartida en cada controlador.
     *
     * @return array{type: string, value: float, instructions: ?string, label: ?string}|null
     */
    public function depositPolicy(): ?array
    {
        if (! $this->hasFeature('booking_deposit')) {
            return null;
        }

        $type = (string) ($this->schedulingSetting('deposit_type') ?? DepositCalculator::TYPE_NONE);
        $value = (float) ($this->schedulingSetting('deposit_value') ?? 0);

        if ($type === DepositCalculator::TYPE_NONE || $value <= 0) {
            return null;
        }

        return [
            'type' => $type,
            'value' => $value,
            'instructions' => $this->schedulingSetting('deposit_instructions'),
            'label' => DepositCalculator::label($type, $value),
        ];
    }

    /** Cuanto hay que abonar por un total, segun la politica vigente. */
    public function depositFor(float $total): float
    {
        $policy = $this->depositPolicy();

        return $policy === null
            ? 0.0
            : DepositCalculator::forTotal($total, $policy['type'], $policy['value']);
    }
}
