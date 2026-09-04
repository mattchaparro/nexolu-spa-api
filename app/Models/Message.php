<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un mensaje que el sistema quiso mandar.
 *
 * "Quiso" y no "mando": la fila se crea ANTES de intentar el envio, y sobrevive
 * al fallo. Un mensaje que solo existe cuando el envio funciona no se puede
 * reintentar ni explicar.
 */
class Message extends Model
{
    use BelongsToBusiness;

    /** Nadie lo va a mandar solo: lo copia una persona. */
    public const STATUS_MANUAL = 'pendiente_manual';

    /** En cola para salir automatico. */
    public const STATUS_PENDING = 'pendiente';

    public const STATUS_SENT = 'enviado';

    public const STATUS_FAILED = 'fallido';

    public const KIND_REMINDER = 'recordatorio';

    public const KIND_SURVEY = 'encuesta';

    public const KIND_STAGE = 'etapa';

    public const KIND_STAFF = 'equipo';

    public const KIND_WAITLIST = 'lista_espera';

    /**
     * "Terminaste, registralo".
     *
     * Tipo propio y no `KIND_STAFF`, aunque los dos vayan a la misma persona.
     * El indice unico es (appointment_id, kind): compartir tipo con el aviso
     * de etapa haria que una cita que ya movio de etapa se trague este aviso
     * en silencio -- y el sintoma seria "a veces no me llega", que es la clase
     * de bug que nadie reporta bien.
     */
    public const KIND_SERVICE_DONE = 'servicio_terminado';

    /**
     * Lo que contesta el agente de WhatsApp.
     *
     * Sale por el outbox como todo lo demas: queda escrito, se puede auditar
     * y en modo manual alguien lo manda a mano en vez de perderse. Sin cita
     * asociada, asi que el indice unico (appointment_id, kind) no lo limita
     * -- una conversacion son muchos mensajes.
     */
    public const KIND_AGENT = 'agente';

    protected $fillable = [
        'business_id', 'location_id', 'kind', 'to', 'client_id', 'appointment_id',
        'body', 'template_name', 'template_language', 'template_params',
        'status', 'attempts', 'sent_at', 'failed_at', 'error', 'sent_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'template_params' => 'array',
        ];
    }

    /**
     * Si este mensaje sale como plantilla en vez de texto libre.
     *
     * Lo llevan los que INICIA el negocio -- recordatorios, cupos liberados,
     * encuestas -- porque fuera de la ventana de 24h Meta rechaza el texto.
     * La respuesta del agente no la lleva: esa contesta dentro de una
     * conversacion que abrio la clienta.
     */
    public function usesTemplate(): bool
    {
        return $this->template_name !== null;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Todavia no salio: o espera a una persona, o espera al canal. */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_MANUAL, self::STATUS_PENDING], true);
    }

    /**
     * Como se le explica el estado a quien administra.
     *
     * En la pantalla, no en el codigo: `pendiente_manual` no le dice nada a
     * nadie que no haya leido esta clase.
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_MANUAL => 'Por enviar a mano',
            self::STATUS_PENDING => 'En cola',
            self::STATUS_SENT => 'Enviado',
            self::STATUS_FAILED => 'Falló',
        ];
    }
}
