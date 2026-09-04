<?php

namespace App\Models;

use App\Support\BusinessFeaturePresets;
use App\Support\BusinessPlanLimits;
use App\Support\Money\CommissionPolicy;
use App\Support\Money\DepositCalculator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Business extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'vertical', 'timezone', 'country_code', 'currency', 'messaging_mode',
        'phone', 'email', 'address', 'logo_path', 'cover_path',
        'public_profile', 'feature_flags', 'plan_limits', 'subscription_plan', 'scheduling_settings', 'commission_settings', 'is_active',
        'appointment_workflow_id',
    ];

    /**
     * `manual` tambien EN MEMORIA, no solo como default de la columna.
     *
     * Sin esto, un negocio recien creado tiene `messaging_mode` null hasta que
     * alguien lo relee de la base: el default de MySQL no vuelve solo. Cada
     * camino que preguntara "¿este negocio manda solo?" sobre la instancia
     * fresca leeria null, y aunque la respuesta segura es la misma, la
     * pantalla mostraria un modo vacio.
     */
    protected $attributes = [
        'messaging_mode' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'feature_flags' => 'array',
            'plan_limits' => 'array',
            'public_profile' => 'array',
            'scheduling_settings' => 'array',
            'commission_settings' => 'array',
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

    /**
     * Todo negocio nace con su sede Principal.
     *
     * Va en el modelo y no en el controlador de alta porque hay tres caminos
     * que crean negocios -- el panel de superadmin, el seeder y las factories
     * de pruebas -- y un negocio sin sede deja a su gente sin donde trabajar y
     * a la rejilla sin columnas. Es la clase de hueco que solo aparece por el
     * camino que alguien olvido tocar.
     */
    protected static function booted(): void
    {
        static::created(function (Business $business) {
            /*
             * Insert directo, no `Location::create()`.
             *
             * `BelongsToBusiness` sobrescribe el `business_id` con el del
             * usuario autenticado, y quien crea un negocio es un superadmin
             * logueado en OTRO: la sede del negocio nuevo terminaria colgando
             * del negocio del superadmin. Aca el business_id no viene del
             * cliente, viene de la fila que se acaba de crear.
             */
            DB::table('locations')->insert([
                'business_id' => $business->id,
                'name' => 'Principal',
                'slug' => 'principal',
                'address' => $business->address,
                'phone' => $business->phone,
                'is_primary' => true,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * La sede a la que cae lo que no diga otra cosa.
     *
     * Devuelve null solo si al negocio le faltara la sede principal, que no
     * deberia pasar: la migracion se la crea a todos y `LocationController`
     * no deja apagar ni quedarse sin la principal.
     */
    public function primaryLocation(): ?Location
    {
        return $this->locations()->where('is_primary', true)->first()
            ?? $this->locations()->where('is_active', true)->first();
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

    /**
     * Sobre que valor se paga comision para un origen de descuento.
     *
     * Mismo patron que `schedulingSetting()`: lo del negocio manda, y el
     * default de plataforma responde. Es el UNICO camino para leer esto.
     */
    public function commissionBaseFor(string $source): string
    {
        $key = CommissionPolicy::settingKey($source);

        $value = data_get($this->commission_settings, $key)
            ?? config("spa.defaults.{$key}")
            ?? CommissionPolicy::BASE_CHARGED;

        // Un valor invalido cae a `charged`, que es el conservador: al reves,
        // un typo en la configuracion pagaria comision sobre plata que no
        // entro y se descubriria en la nomina.
        return in_array($value, CommissionPolicy::bases(), true)
            ? $value
            : CommissionPolicy::BASE_CHARGED;
    }

    /**
     * La politica completa, para el checkout y para la pantalla.
     *
     * @return array<string, string>
     */
    public function commissionBases(): array
    {
        $result = [];

        foreach (CommissionPolicy::sources() as $source) {
            $result[$source] = $this->commissionBaseFor($source);
        }

        return $result;
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

    /**
     * La cuenta de Instagram conectada, si la hay.
     *
     * `hasOne` y no `hasMany` porque el indice unico ya garantiza una por red
     * y por negocio: dos cuentas activas del mismo spa es una publicacion que
     * sale dos veces.
     */
    public function instagramAccount(): HasOne
    {
        return $this->hasOne(BusinessSocialAccount::class)
            ->where('provider', BusinessSocialAccount::PROVIDER_INSTAGRAM);
    }

    /**
     * Si al cerrar un servicio se le pide a quien atendio la foto del trabajo.
     *
     * Se PIDE, nunca se exige. Bloquear el cobro por una foto que falta
     * termina siempre en uno de dos sitios: una foto cualquiera subida para
     * poder cobrar, o una caja que no cierra a las nueve de la noche. Un
     * pendiente visible convence mejor que un candado.
     *
     * Va detras de `client_history`: pedir una foto que el negocio despues no
     * puede ver en la ficha seria pedirla para nada.
     */
    public function asksForServicePhoto(): bool
    {
        return $this->hasFeature('client_history')
            && $this->schedulingSetting('service_photo_policy') === 'ask';
    }

    /**
     * Si al cobrar se pide el comprobante, dado el medio de pago.
     *
     * `non_cash` es la politica util: el efectivo se cuenta en el cajon al
     * cerrar el dia y no necesita foto. Una transferencia no se puede contar
     * -- sin comprobante, el cierre cuadra contra lo que alguien dijo que
     * entro, que es exactamente lo que el cierre existe para no hacer.
     */
    public function asksForPaymentProof(bool $countsAsCash): bool
    {
        return match ((string) $this->schedulingSetting('payment_proof_policy')) {
            'always' => true,
            'non_cash' => ! $countsAsCash,
            default => false,
        };
    }

    /**
     * Cuantos minutos despues de terminar el servicio se le avisa a quien
     * atendio. 0 = nadie recibe nada.
     */
    public function serviceDoneReminderMinutes(): int
    {
        return max(0, (int) $this->schedulingSetting('service_done_reminder_min'));
    }

    /**
     * Topes ya resueltos: el preset del plan mas las excepciones del negocio.
     *
     * Misma mezcla y misma razon que `resolvedFeatureFlags()`: el front lee
     * ESTO y nunca la recalcula. Dos implementaciones de la misma regla es
     * como el POS termino mostrandole modulos no contratados a negocios del
     * plan Basico.
     *
     * @return array<string, int|null>
     */
    public function resolvedPlanLimits(): array
    {
        $defaults = BusinessPlanLimits::fromPlan($this->subscription_plan);
        $explicit = $this->plan_limits;

        if (empty($explicit)) {
            return $defaults;
        }

        return array_merge($defaults, $explicit);
    }

    /** El tope de una llave, o null si no tiene. */
    public function limitFor(string $key): ?int
    {
        $limit = $this->resolvedPlanLimits()[$key] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /**
     * Cuantos hay hoy contra ese tope.
     *
     * Se cuenta solo lo ACTIVO: desactivar a alguien libera el cupo. Contar
     * los inactivos dejaria a un local que rota personal chocando contra un
     * tope que no puede explicarse.
     */
    public function usageFor(string $key): int
    {
        return match ($key) {
            BusinessPlanLimits::MAX_RESOURCES => $this->resources()
                ->where('type', Resource::TYPE_STAFF)
                ->where('is_active', true)
                ->count(),

            // Igual que con la gente: se cuenta solo lo activo. Una sede
            // cerrada libera el cupo, y por eso una sede se apaga en vez de
            // borrarse -- lo que se atendio ahi no puede desaparecer.
            BusinessPlanLimits::MAX_LOCATIONS => $this->locations()
                ->where('is_active', true)
                ->count(),

            default => 0,
        };
    }

    /** Si cabe uno mas de `$key`. */
    public function canAddWithinLimit(string $key): bool
    {
        return BusinessPlanLimits::allows($this->limitFor($key), $this->usageFor($key));
    }

    /**
     * Tope y uso de cada llave, para que la pantalla lo diga ANTES de que
     * alguien choque contra el.
     *
     * @return array<string, array{limit: int|null, used: int, remaining: int|null}>
     */
    public function planUsage(): array
    {
        $result = [];

        foreach (BusinessPlanLimits::catalog() as $key) {
            $limit = $this->limitFor($key);
            $used = $this->usageFor($key);

            $result[$key] = [
                'limit' => $limit,
                'used' => $used,
                // Nunca negativo: un negocio que quedo por encima de su tope
                // (bajo de plan) muestra 0 disponibles, no "-2 disponibles".
                'remaining' => $limit === null ? null : max(0, $limit - $used),
            ];
        }

        return $result;
    }
}
