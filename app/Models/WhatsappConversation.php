<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La conversacion de WhatsApp de una persona con UN negocio.
 *
 * Existe porque el codigo del negocio viaja una sola vez -- en el texto
 * prellenado del enlace -- y del segundo mensaje en adelante lo unico que
 * sabe de quien es la charla es esta fila.
 */
class WhatsappConversation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'phone', 'ia_conversation_id', 'client_id', 'last_message_at',
    ];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
