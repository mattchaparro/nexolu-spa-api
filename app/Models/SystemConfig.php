<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuracion global editable sin deploy (superadmin), clave/valor plano.
 * No se lee directo - siempre a traves de App\Support\SystemConfigStore.
 */
#[Fillable(['key', 'value'])]
class SystemConfig extends Model
{
    //
}
