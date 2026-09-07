<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\LocationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los numeritos del menu: que hay pendiente y donde.
 *
 * UN endpoint para todos los contadores, y no uno por pantalla. El menu se
 * pinta en cada pagina de la aplicacion, asi que este es de los pocos
 * llamados que se repiten todo el dia: dos peticiones cada minuto por cada
 * persona conectada, en un servidor de un solo core, es un costo que no
 * compra nada.
 *
 * Y son CONTEOS, no listas. Para saber que hay tres conversaciones sin
 * contestar no hace falta traerlas: traerlas seria mandar los telefonos y los
 * nombres de tres clientas en cada carga de pagina.
 */
class NavBadgesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = LocationScope::for($user);

        return response()->json([
            /*
             * Sin leer = nunca se abrio, o llego algo despues de la ultima
             * vez que se abrio. Comparar las dos columnas y no confiar en un
             * contador guardado: un contador hay que mantenerlo sincronizado
             * y se desincroniza.
             */
            'inbox_unread' => WhatsappConversation::query()
                ->where('status', WhatsappConversation::STATUS_OPEN)
                ->where(fn ($q) => $q
                    ->whereNull('read_at')
                    ->orWhereColumn('last_inbound_at', '>', 'read_at'))
                ->count(),

            /*
             * Lo que espera que una persona lo mande. `pendiente` a secas no
             * cuenta: eso sale solo por la cola.
             */
            'outbox_pending' => Message::query()
                ->where('status', Message::STATUS_MANUAL)
                ->when(
                    ! $scope->seesAll(),
                    fn ($q) => $q->where(fn ($sub) => $sub
                        ->whereNull('location_id')
                        ->orWhereIn('location_id', $scope->locationIds ?? [])),
                )
                ->count(),
        ]);
    }
}
