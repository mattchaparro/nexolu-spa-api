<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\ClientPhoto;
use App\Support\AgendaScope;
use App\Support\ImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que se sube al cerrar un servicio: la foto del trabajo y el comprobante
 * de lo que entro.
 *
 * POR QUE ESTO NO ES `ClientProfileController::storePhoto`. Esa ruta pide
 * `clientes.gestionar`, y el rol de profesional NO LO TIENE -- a proposito:
 * la base de clientes con telefonos es el activo del negocio y no se reparte
 * al equipo entero. Pero fotografiar el trabajo que uno acaba de hacer no es
 * administrar la ficha de nadie. Sin esta separacion, la unica forma de que
 * la manicurista suba su foto seria darle acceso a toda la clientela, que es
 * pagar demasiado por una funcion pequena.
 *
 * EL LIMITE ES LA AGENDA, NO LA FICHA: se puede subir a una cita que uno
 * atendio. Quien tiene `citas.ver_todas` -- recepcion, la duena -- puede en
 * cualquiera, porque es quien cobra por las demas. Es el mismo criterio de
 * AgendaScope, que ya decide de quien es cada columna del calendario.
 */
class ServiceClosingController
{
    /**
     * La foto del trabajo hecho.
     *
     * SIN INTELIGENCIA ARTIFICIAL de por medio. Esta imagen es la evidencia
     * de como quedo el trabajo -- lo que se mira para evaluarlo y lo que la
     * profesional consulta la proxima vez -- y una version retocada no sirve
     * para ninguna de las dos cosas. Embellecer es una decision aparte, sobre
     * una copia, y solo si esa foto llega a una publicacion.
     */
    public function storePhoto(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'photo' => ImageStorage::rules(required: true),
            'caption' => ['nullable', 'string', 'max:255'],
            'appointment_item_id' => ['nullable', 'integer'],

            /*
             * "¿Te puedo publicar esta foto?" -- y este es el mejor momento
             * para preguntarlo: la clienta acaba de levantarse de la silla y
             * esta ahi mirandose las manos. Ausente = no.
             */
            'marketing_consent' => ['nullable', 'boolean'],
        ]);

        $item = $this->itemFor($request, $appointment, $data['appointment_item_id'] ?? null);

        if ($appointment->client_id === null) {
            /*
             * Una cita sin ficha -- alguien que llego sin agendar y solo dejo
             * un nombre -- no tiene donde colgar la foto. Se dice, en vez de
             * crear una ficha a medias que nadie pidio.
             */
            return response()->json([
                'message' => 'Esta cita no tiene ficha de cliente. Crea la ficha para poder guardar la foto.',
            ], 422);
        }

        $consent = (bool) ($data['marketing_consent'] ?? false);

        $photo = ClientPhoto::create([
            'business_id' => $appointment->business_id,
            'client_id' => $appointment->client_id,
            'appointment_item_id' => $item->id,
            'image_path' => ImageStorage::store($request->file('photo'), $appointment->business_id, 'trabajos'),
            'caption' => $data['caption'] ?? null,
            // La hora del TRABAJO, no la de la subida: si alguien la sube al
            // otro dia, la foto sigue siendo del servicio de ayer.
            'taken_at' => $item->service_ends_at ?? now(),
            'uploaded_by_user_id' => $request->user()->id,
            'marketing_consent_at' => $consent ? now() : null,
            'marketing_consent_by_user_id' => $consent ? $request->user()->id : null,
        ]);

        return response()->json([
            'id' => $photo->id,
            'url' => ImageStorage::url($photo->image_path),
            'marketing_consent' => $photo->allowsMarketing(),
        ], 201);
    }

    /**
     * El comprobante de la transferencia.
     *
     * Es la imagen que hoy viaja por el grupo de WhatsApp junto a un texto
     * -- "unas semipermanente de cuarenta mil" -- cuyo contenido YA ESTA en
     * esta base. Con el comprobante colgado de la cita, ese mensaje deja de
     * hacer falta y el cierre del dia pasa a cuadrar contra algo que se puede
     * mirar, en vez de contra lo que alguien reporto.
     *
     * UNA por cita: se cobra la cita completa con un medio de pago. Subir otra
     * reemplaza la anterior, que es lo que uno quiere cuando la primera salio
     * movida.
     */
    public function storePaymentProof(Request $request, Appointment $appointment): JsonResponse
    {
        $request->validate(['proof' => ImageStorage::rules(required: true)]);

        $this->itemFor($request, $appointment, null);

        // La anterior se borra: un comprobante reemplazado que sigue en el
        // bucket es basura que nadie va a ir a buscar.
        ImageStorage::delete($appointment->payment_proof_path);

        $appointment->forceFill([
            'payment_proof_path' => ImageStorage::store(
                $request->file('proof'),
                $appointment->business_id,
                'comprobantes',
            ),
        ])->save();

        return response()->json([
            'payment_proof_url' => ImageStorage::url($appointment->payment_proof_path),
        ], 201);
    }

    /**
     * El item al que se cuelga lo subido, comprobando que sea de quien sube.
     *
     * Devuelve el ultimo item de la cita cuando no se pide uno: es el que
     * cierra el trabajo, y es el que el aviso de fin de servicio nombra.
     */
    private function itemFor(Request $request, Appointment $appointment, ?int $itemId): AppointmentItem
    {
        $scope = AgendaScope::for($request->user());

        $items = $appointment->items()->with('service')->get();

        if ($itemId !== null) {
            $items = $items->where('id', $itemId);
        }

        /*
         * Sin `citas.ver_todas` solo cuentan los items propios. La cita
         * completa puede tener tres servicios de tres personas: fotografiar
         * el trabajo de otra no es asunto de uno.
         */
        if ($scope->resourceId !== null) {
            $items = $items->where('resource_id', $scope->resourceId);
        }

        // 404 y no 403: una cita que no es suya no deberia ni confirmarle que
        // existe. Mismo criterio que la lista de espera y los mensajes.
        abort_if($scope->seesNothing() || $items->isEmpty(), 404);

        return $items->sortByDesc('service_ends_at')->first();
    }
}
