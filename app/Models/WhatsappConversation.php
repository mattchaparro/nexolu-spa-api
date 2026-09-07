<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La conversacion de WhatsApp de una persona con UN negocio.
 *
 * Existe porque el codigo del negocio viaja una sola vez -- en el texto
 * prellenado del enlace -- y del segundo mensaje en adelante lo unico que
 * sabe de quien es la charla es esta fila.
 *
 * Desde que hay bandeja guarda ademas quien esta atendiendo: si el agente
 * contesta o se hizo a un lado, y hasta cuando.
 */
class WhatsappConversation extends Model
{
    use BelongsToBusiness;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /**
     * Cuanto dura la ventana de texto libre de Meta, en horas.
     *
     * No es configurable porque no es nuestra: fuera de estas 24 horas desde
     * el ultimo mensaje de la persona, Meta solo entrega plantillas
     * aprobadas. ManyChat vive con la misma regla.
     */
    private const VENTANA_HORAS = 24;

    protected $fillable = [
        'business_id', 'phone', 'ia_conversation_id', 'client_id',
        'agent_paused_until', 'assigned_user_id',
        'last_message_at', 'last_inbound_at', 'read_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'agent_paused_until' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    /**
     * Si el agente debe quedarse callado en esta conversacion.
     *
     * Una fecha y no un interruptor: quien toma una conversacion no siempre
     * se acuerda de soltarla, y un booleano olvidado deja al agente mudo para
     * siempre sin que nadie lo note.
     */
    public function agentIsPaused(): bool
    {
        return $this->agent_paused_until !== null && $this->agent_paused_until->isFuture();
    }

    /**
     * Callar al agente porque entro una persona.
     *
     * Se EXTIENDE, no se reemplaza: si alguien ya la habia tomado por dos
     * horas y escribe otra vez, el reloj vuelve a empezar en vez de acortarse.
     */
    public function pauseAgent(?int $minutos = null): void
    {
        $minutos ??= (int) config('spa.defaults.whatsapp_agent_pause_min');

        $this->update(['agent_paused_until' => now()->addMinutes(max(1, $minutos))]);
    }

    /** Devolverle la conversacion al agente. */
    public function resumeAgent(): void
    {
        $this->update(['agent_paused_until' => null, 'assigned_user_id' => null]);
    }

    /**
     * Si todavia se puede mandar texto libre.
     *
     * Fuera de la ventana el mensaje NO rebota con un error claro: Meta lo
     * acepta y no lo entrega. Por eso la pantalla tiene que saberlo antes de
     * dejar escribir.
     */
    public function windowIsOpen(): bool
    {
        return $this->last_inbound_at !== null
            && $this->last_inbound_at->gt(CarbonImmutable::now()->subHours(self::VENTANA_HORAS));
    }

    /** Cuando se cierra la ventana, o null si ya esta cerrada. */
    public function windowClosesAt(): ?CarbonImmutable
    {
        if (! $this->windowIsOpen()) {
            return null;
        }

        return CarbonImmutable::parse($this->last_inbound_at)->addHours(self::VENTANA_HORAS);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null
            || ($this->last_inbound_at !== null && $this->last_inbound_at->gt($this->read_at));
    }
}
