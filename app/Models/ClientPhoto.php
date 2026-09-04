<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientPhoto extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'client_id', 'appointment_item_id',
        'image_path', 'caption', 'taken_at', 'uploaded_by_user_id',
        'marketing_consent_at', 'marketing_consent_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
        ];
    }

    /**
     * La clienta dijo que si a que su foto salga en las redes del negocio.
     *
     * Es lo UNICO que autoriza a sacar esta foto de la ficha. Que la foto se
     * vea muy bien, que la clienta sea simpatica o que "total, no se le ve la
     * cara" no son permisos. El modulo de publicaciones filtra por esto y no
     * ofrece ninguna forma de saltarselo.
     */
    public function allowsMarketing(): bool
    {
        return $this->marketing_consent_at !== null;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function appointmentItem(): BelongsTo
    {
        return $this->belongsTo(AppointmentItem::class);
    }

    /** Quien anoto el consentimiento, para poder preguntarle. */
    public function marketingConsentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marketing_consent_by_user_id');
    }
}
