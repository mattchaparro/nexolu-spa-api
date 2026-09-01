<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Mis citas": lo que un cliente puede hacer con las suyas, sin cuenta.
 *
 * TRES REGLAS QUE NO SON OBVIAS, y las tres nacen del mismo error ajeno:
 * Blue Souls exponia `/api/external/*` con throttle y nada mas, y ahi se podian
 * enumerar clientes con telefono, crear citas y borrarlas sin credenciales.
 *
 * 1. NO SE CONSULTA POR TELEFONO. Un telefono no es un secreto: esta en la
 *    vitrina, en Instagram, en un grupo de WhatsApp. Probar numeros no puede
 *    ser la forma de leer nombres y horarios de clientas ajenas. Se entra por
 *    un token que solo viajo en el mensaje que el negocio le mando a esa
 *    persona.
 *
 * 2. SOLO SE MUEVE LA HORA. Ni la persona, ni el servicio, ni la sede: eso es
 *    reservar de nuevo, y para eso ya esta la pagina publica con todas sus
 *    validaciones. Reagendar de verdad se reduce a "el sabado no puedo, ¿me
 *    lo pasas al domingo?", y hacerlo asi mantiene el precio, la comision y el
 *    abono exactamente donde estaban.
 *
 * 3. LO QUE YA PASO NO SE TOCA. Una cita cobrada, cancelada, o con menos
 *    preaviso del que el negocio pide, se lee pero no se mueve. El preaviso es
 *    `min_cancellation_notice_min`, que hasta ahora era un ajuste que nadie
 *    aplicaba.
 */
class ClientPortalService
{
    /**
     * El token de un cliente, creandolo si aun no tiene.
     *
     * No se generan por adelantado: un token que nunca se mando es superficie
     * de ataque sin ninguna ventaja.
     */
    public function tokenFor(Client $client): string
    {
        if (! empty($client->portal_token)) {
            return $client->portal_token;
        }

        $token = Str::random(48);
        $client->forceFill(['portal_token' => $token])->save();

        return $token;
    }

    /**
     * Quema el token actual y entrega uno nuevo.
     *
     * Para cuando alguien reenvio el enlace a otra persona, o el telefono
     * cambio de dueno. El enlace viejo deja de abrir nada.
     */
    public function rotate(Client $client): string
    {
        $client->forceFill(['portal_token' => null])->save();

        return $this->tokenFor($client);
    }

    /**
     * El cliente detras de un token, o null.
     *
     * `withoutGlobalScopes` porque esto corre SIN sesion: el scope de negocio
     * es inerte ahi, y hacerlo explicito evita que parezca protegido cuando no
     * lo esta. El token ya identifica al negocio por si solo.
     */
    public function resolve(?string $token): ?Client
    {
        if ($token === null || strlen($token) < 32) {
            return null;
        }

        return Client::withoutGlobalScopes()
            ->where('portal_token', $token)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Las citas que esa persona puede ver.
     *
     * Solo las FUTURAS y las de hoy. El historial completo -- cuanto gasto,
     * que se hizo hace ocho meses -- es del negocio y no tiene por que viajar
     * a un enlace que puede quedar abierto en un telefono prestado.
     *
     * @return Collection<int, Appointment>
     */
    public function upcoming(Client $client, Business $business): Collection
    {
        $tz = $business->businessTimezone();

        return Appointment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('client_id', $client->id)
            ->where('starts_at', '>=', CarbonImmutable::now($tz)->startOfDay()->utc())
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
            ])
            ->with(['items.service', 'items.resource', 'location'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Si todavia esta a tiempo de moverla o cancelarla.
     *
     * El preaviso lo pone el negocio. Sin el, alguien cancela a las 8:55 una
     * cita de las 9:00 y esa hora ya no se vuelve a vender: el local pierde la
     * manana y la profesional su comision.
     */
    public function canBeChanged(Appointment $appointment, Business $business): bool
    {
        if ($appointment->checked_out_at !== null) {
            return false;
        }

        if (! in_array($appointment->status, [
            Appointment::STATUS_PENDING,
            Appointment::STATUS_CONFIRMED,
        ], true)) {
            return false;
        }

        $notice = (int) $business->schedulingSetting('min_cancellation_notice_min');

        return CarbonImmutable::parse($appointment->starts_at)
            ->subMinutes($notice)
            ->isFuture();
    }

    /** Como se le explica a la persona por que no puede moverla. */
    public function reasonToRefuse(Appointment $appointment, Business $business): ?string
    {
        if ($this->canBeChanged($appointment, $business)) {
            return null;
        }

        if ($appointment->checked_out_at !== null) {
            return 'Esta cita ya fue atendida.';
        }

        if (! in_array($appointment->status, [
            Appointment::STATUS_PENDING,
            Appointment::STATUS_CONFIRMED,
        ], true)) {
            return 'Esta cita ya no está activa.';
        }

        $notice = (int) $business->schedulingSetting('min_cancellation_notice_min');
        $horas = (int) ceil($notice / 60);

        return $horas >= 1
            ? "Para cambiarla o cancelarla necesitamos al menos {$horas} hora(s) de anticipación. Escríbenos y lo vemos."
            : 'Ya no se puede cambiar por internet. Escríbenos y lo vemos.';
    }
}
