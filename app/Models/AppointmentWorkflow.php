<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de flujo. Vive a nivel plataforma, igual que en el POS: un negocio
 * elige una, no la escribe.
 */
class AppointmentWorkflow extends Model
{
    protected $fillable = ['name', 'description', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(AppointmentWorkflowStage::class, 'workflow_id')->orderBy('sort_order');
    }

    /** Los negocios que lo usan. Para saber a quien afecta cambiarlo. */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'appointment_workflow_id');
    }

    /** La etapa en la que cae una cita nueva. */
    public function initialStage(): ?AppointmentWorkflowStage
    {
        return $this->stages()->where('is_initial', true)->first() ?? $this->stages()->first();
    }

    /**
     * La etapa que representa un estado nucleo.
     *
     * Cuando el negocio definio dos que apuntan al mismo -- "Confirmada por
     * WhatsApp" y "Confirmada por telefono" -- gana la primera en orden. Es una
     * eleccion arbitraria pero estable, y solo aplica cuando la transicion la
     * dispara el sistema y no una persona eligiendo etapa.
     */
    public function stageForStatus(string $status): ?AppointmentWorkflowStage
    {
        return $this->stages->firstWhere('maps_to_status', $status);
    }
}
