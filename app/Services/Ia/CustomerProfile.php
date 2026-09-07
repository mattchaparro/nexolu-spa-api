<?php

namespace App\Services\Ia;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;

/**
 * Con quien esta hablando el agente.
 *
 * Es el gemelo de `BusinessProfile`: uno dice quien es el negocio, este dice
 * quien es la persona del otro lado. Sin el, el agente le pregunta el nombre
 * a una clienta que lleva tres anos viniendo -- y cada mensaje empieza de
 * cero, que es exactamente lo que hace que un bot se sienta un bot.
 *
 * Tambien evita el paso mas caro de todos: preguntar algo que ya sabemos.
 */
class CustomerProfile
{
    public function for(Business $business, string $phone, ?Client $client): string
    {
        if ($client === null) {
            return 'Es la primera vez que este número escribe. No sabes su nombre: '
                .'pregúntaselo antes de agendar.';
        }

        $lineas = ['Hablas con '.$client->fullName().'.'];

        $ultima = Appointment::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('client_id', $client->id)
            ->whereNotNull('checked_out_at')
            ->with('items.service')
            ->latest('starts_at')
            ->first();

        if ($ultima !== null) {
            $tz = $business->businessTimezone();
            $servicio = $ultima->items->first()?->service?->name;

            /*
             * La ultima visita, porque "lo mismo de la otra vez" es como pide
             * la cita la mitad de la gente. Sin este dato el agente tiene que
             * preguntar que se hizo, y quien lo pregunta es alguien que
             * claramente no la conoce.
             */
            $lineas[] = 'Ya es clienta: su última visita fue el '
                .$ultima->starts_at?->setTimezone($tz)->locale('es')->isoFormat('D [de] MMMM')
                .($servicio ? ' ('.$servicio.')' : '').'.';
        }

        // El nombre ya lo tienes: usarlo y no volver a pedirlo es la
        // diferencia entre una recepcionista y un formulario.
        $lineas[] = 'No le vuelvas a preguntar el nombre.';

        return implode(' ', $lineas);
    }
}
