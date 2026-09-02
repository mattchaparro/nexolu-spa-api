<?php

namespace App\Services\WhatsApp;

use App\Models\Business;
use App\Models\Client;
use App\Models\WhatsappConversation;
use Illuminate\Support\Facades\DB;

/**
 * De que negocio es este mensaje.
 *
 * Es la pregunta mas delicada de todo el agente: responderla mal pone a una
 * clienta hablando con el spa equivocado -- viendo horarios, precios y, si
 * nos descuidamos, citas que no son de ese local.
 *
 * Se responde en tres pasos, del mas confiable al menos:
 *
 *  1. EL NUMERO QUE RECIBIO. Si el negocio tiene WhatsApp propio, el
 *     `phone_number_id` ya lo identifica. Nada de lo que escriba nadie puede
 *     contradecir esto.
 *  2. LA CONVERSACION ABIERTA. Si esa persona ya venia hablando por el numero
 *     compartido, sigue donde estaba.
 *  3. EL CODIGO DEL ENLACE. Solo para estrenar conversacion por el numero
 *     compartido.
 *
 * El orden importa: el codigo va de ULTIMO porque es lo unico que viaja
 * dentro de texto que la clienta puede editar. Si fuera primero, escribir el
 * codigo de otro spa cambiaria de negocio a mitad de conversacion.
 */
class ConversationRouter
{
    /**
     * El codigo dentro del texto: "... (NX-4F2A)" o "NX-4F2A" suelto.
     *
     * Se busca en cualquier parte del mensaje porque la gente escribe encima
     * del texto prellenado sin borrarlo del todo.
     */
    private const CODE_PATTERN = '/\b(NX-[A-Z0-9]{4,8})\b/i';

    /**
     * @param  string|null  $phoneNumberId  el numero de WhatsApp que recibio el mensaje
     * @param  string  $from  telefono de quien escribe, ya normalizado
     */
    public function resolve(?string $phoneNumberId, string $from, string $text): ?WhatsappConversation
    {
        $business = $this->businessByOwnNumber($phoneNumberId);

        if ($business !== null) {
            return $this->conversationFor($business, $from);
        }

        // Numero compartido: primero lo que ya estaba abierto.
        $abierta = $this->openConversationFor($from);

        // ...salvo que traiga un codigo distinto, que es como se cambia de
        // negocio a proposito: la clienta toco el enlace de OTRO spa.
        $porCodigo = $this->businessByCode($text);

        if ($porCodigo !== null && $porCodigo->id !== $abierta?->business_id) {
            return $this->conversationFor($porCodigo, $from);
        }

        if ($abierta !== null) {
            return $abierta;
        }

        return $porCodigo === null ? null : $this->conversationFor($porCodigo, $from);
    }

    /** El negocio dueño del numero que recibio, si ese numero es de alguien. */
    private function businessByOwnNumber(?string $phoneNumberId): ?Business
    {
        if ($phoneNumberId === null || $phoneNumberId === '') {
            return null;
        }

        return Business::withoutGlobalScopes()
            ->where('whatsapp_phone_number_id', $phoneNumberId)
            ->where('is_active', true)
            ->first();
    }

    /** El negocio cuyo codigo viene en el texto del enlace. */
    private function businessByCode(string $text): ?Business
    {
        if (! preg_match(self::CODE_PATTERN, $text, $m)) {
            return null;
        }

        return Business::withoutGlobalScopes()
            ->whereRaw('UPPER(whatsapp_code) = ?', [strtoupper($m[1])])
            ->where('is_active', true)
            ->first();
    }

    /**
     * La conversacion mas reciente de ese telefono, sea con quien sea.
     *
     * Solo aplica al numero compartido. Si la persona habla con dos spas por
     * el mismo numero, gana la ultima -- y para cambiarse vuelve a tocar el
     * enlace del otro, que trae su codigo.
     */
    private function openConversationFor(string $from): ?WhatsappConversation
    {
        return WhatsappConversation::withoutGlobalScopes()
            ->where('phone', $from)
            ->orderByDesc('last_message_at')
            ->with('business')
            ->first();
    }

    private function conversationFor(Business $business, string $from): WhatsappConversation
    {
        /*
         * `updateOrCreate` y no un find-then-create: dos mensajes seguidos de
         * la misma persona llegan casi a la vez y crearian dos filas. El
         * indice unico (business_id, phone) lo impide, pero mejor no chocar
         * contra el en cada rafaga.
         */
        $conversacion = DB::transaction(fn () => WhatsappConversation::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $business->id, 'phone' => $from],
            ['last_message_at' => now()],
        ));

        // La ficha, si ya existe. Puede no existir: una primera vez es un
        // telefono sin nada detras, y la crea el agente al agendar.
        if ($conversacion->client_id === null) {
            $client = Client::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('phone', $from)
                ->first();

            if ($client !== null) {
                $conversacion->update(['client_id' => $client->id]);
            }
        }

        $conversacion->setRelation('business', $business);

        return $conversacion;
    }
}
