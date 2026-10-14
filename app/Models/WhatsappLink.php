<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * Un enlace wa.me guardado: al tocarlo se abre el WhatsApp del negocio con
 * este mensaje ya escrito.
 */
class WhatsappLink extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'name', 'message'];
}
