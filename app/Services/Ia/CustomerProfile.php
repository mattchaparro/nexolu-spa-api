<?php

namespace App\Services\Ia;

use App\Ai\NombreRaro;
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

        /*
         * El nombre de la ficha muchas veces es el del perfil de WhatsApp:
         * «.», «🦋 Princesa 🦋», el negocio, el carro. Saludar con eso
         * queda ridiculo, y peor: el bot cree que ya sabe el nombre, no lo
         * pregunta, y la cita queda a nombre de un emoji. Si el nombre no
         * parece de persona, se trata como desconocido.
         */
        if (NombreRaro::es($client->fullName())) {
            return 'La ficha de este número dice llamarse «'.trim((string) $client->fullName()).'», pero eso '
                .'parece el nombre del perfil de WhatsApp, no el de una persona. NO la saludes con ese nombre. '
                .'Pregúntale cómo se llama y si la cita es para ella o para alguien más, y guarda el nombre '
                .'con `guardar_contacto`.';
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
