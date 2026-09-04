<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientPhoto;
use App\Models\SocialPost;
use App\Services\ClientPortalService;
use App\Support\ChannelPhone;
use App\Support\ImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * La ficha del cliente.
 *
 * No es un CRUD: es lo que la profesional consulta ANTES de atender. Por eso
 * lo que pesa aca es el historial, las fotos del trabajo y las notas de
 * cuidado, no el formulario de datos.
 */
class ClientProfileController
{
    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $clients = Client::query()
            ->when($request->boolean('only_active', true), fn ($q) => $q->where('is_active', true))
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%");

                    $digits = preg_replace('/\D/', '', $term) ?? '';

                    if ($digits !== '') {
                        $sub->orWhere('phone', 'like', "%{$digits}%");
                    }
                });
            })
            ->withCount(['appointments as visits' => fn ($q) => $q->where('status', Appointment::STATUS_COMPLETED)])
            ->orderBy('name')
            ->paginate(30);

        return response()->json([
            'data' => collect($clients->items())->map(fn (Client $c) => [
                'id' => $c->id,
                'full_name' => $c->fullName(),
                'phone' => $c->phone,
                'email' => $c->email,
                'visits' => $c->visits,
                'is_active' => (bool) $c->is_active,
            ]),
            'meta' => [
                'total' => $clients->total(),
                'page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
            ],
        ]);
    }

    /**
     * "Mis citas" y el enlace de reserva con sus datos ya puestos.
     *
     * Se arman aca y no en el front porque el token no viaja en ningun otro
     * lado: es el mismo que iria en el mensaje de WhatsApp cuando ese canal
     * exista. Mientras tanto, quien atiende lo copia y lo manda desde su
     * propio telefono, que es exactamente lo que hace hoy con todo.
     *
     * @return array<string, string|null>
     */
    private function links(Client $client, Business $business): array
    {
        $base = rtrim((string) config('app.frontend_url', ''), '/');
        $token = app(ClientPortalService::class)->tokenFor($client);

        return [
            'portal' => "{$base}/mis-citas/{$business->slug}/{$token}",
            // Reservar con el formulario ya lleno con su nombre, telefono y
            // correo. Es el enlace que se le manda a quien pide cita por chat.
            'booking' => "{$base}/reservar/{$business->slug}?c={$token}",
        ];
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        $tz = $request->user()->business->businessTimezone();

        return response()->json([
            'id' => $client->id,
            'name' => $client->name,
            'last_name' => $client->last_name,
            'full_name' => $client->fullName(),
            'phone' => $client->phone,
            'email' => $client->email,
            'birth_date' => $client->birth_date?->toDateString(),
            'gender' => $client->gender,
            'notes' => $client->notes,
            'care_notes' => $client->care_notes,
            'preferred_resource_id' => $client->preferred_resource_id,
            'accepts_marketing' => (bool) $client->accepts_marketing,
            'is_active' => (bool) $client->is_active,
            'created_at' => $client->created_at?->setTimezone($tz)->toDateString(),

            /*
             * Los enlaces personales de esta persona, para copiarlos y
             * mandarlos A MANO.
             *
             * Existen porque WhatsApp todavia no envia nada. Sin esto, "mis
             * citas" y el prellenado del formulario son pantallas a las que
             * nadie puede entrar hasta que Meta apruebe un numero -- y eso
             * bloquea probar el producto entero, no solo la mensajeria.
             *
             * El token se genera al pedir la ficha, no antes: uno que nunca se
             * abrio es superficie de ataque sin ninguna ventaja.
             */
            'links' => $this->links($client, $request->user()->business),
            'stats' => $this->stats($client, $tz),
            'history' => $this->history($client, $tz),
            'photos' => $this->photos($client, $tz),
        ]);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $business = $request->user()->business;

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'gender' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'care_notes' => ['nullable', 'string', 'max:2000'],
            'preferred_resource_id' => ['nullable', 'integer'],
            'accepts_marketing' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['phone'])) {
            $phone = ChannelPhone::normalize($data['phone'], $business->country_code);

            if ($phone === null) {
                throw ValidationException::withMessages(['phone' => ['Ese número no parece válido.']]);
            }

            $duplicate = Client::where('phone', $phone)->where('id', '!=', $client->id)->first();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'phone' => ["Ese número ya es de {$duplicate->fullName()}."],
                ]);
            }

            $data['phone'] = $phone;
        }

        $client->update($data);

        return $this->show($request, $client->fresh());
    }

    public function storePhoto(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'photo' => ImageStorage::rules(required: true),
            'caption' => ['nullable', 'string', 'max:255'],
            'appointment_item_id' => ['nullable', 'integer'],
            'taken_at' => ['nullable', 'date'],

            /*
             * "¿Te puedo publicar esta foto?" -- y este es el momento de
             * preguntarlo: la clienta esta ahi mirandose las manos. Volver a
             * buscarla dos semanas despues, cuando alguien quiera armar la
             * publicacion, es como se termina publicando sin preguntar.
             *
             * Ausente = NO. El silencio no es un permiso.
             */
            'marketing_consent' => ['nullable', 'boolean'],
        ]);

        $business = $request->user()->business;

        // Un item de otra cita, o de otro negocio, no puede colarse: la foto
        // quedaria colgada de un trabajo que no es de este cliente.
        $itemId = null;

        if (! empty($data['appointment_item_id'])) {
            $itemId = AppointmentItem::where('business_id', $business->id)
                ->whereHas('appointment', fn ($q) => $q->where('client_id', $client->id))
                ->where('id', $data['appointment_item_id'])
                ->value('id');
        }

        $photo = ClientPhoto::create([
            'business_id' => $business->id,
            'client_id' => $client->id,
            'appointment_item_id' => $itemId,
            'image_path' => ImageStorage::store($request->file('photo'), $business->id, 'trabajos'),
            'caption' => $data['caption'] ?? null,
            'taken_at' => $data['taken_at'] ?? now(),
            'uploaded_by_user_id' => $request->user()->id,
            'marketing_consent_at' => ($data['marketing_consent'] ?? false) ? now() : null,
            'marketing_consent_by_user_id' => ($data['marketing_consent'] ?? false) ? $request->user()->id : null,
        ]);

        return response()->json([
            'id' => $photo->id,
            'url' => ImageStorage::url($photo->image_path),
            'caption' => $photo->caption,
            'marketing_consent' => $photo->allowsMarketing(),
        ], 201);
    }

    /**
     * La clienta autoriza -- o retira -- que su foto salga en las redes.
     *
     * Es un verbo aparte y no un campo mas de la ficha porque es una decision
     * de OTRA persona, no del negocio. Se puede retirar en cualquier momento
     * y sin explicaciones: quien dijo que si en marzo puede no querer en
     * junio, y la unica forma de que eso sea cierto es que quitarlo sea tan
     * facil como ponerlo.
     *
     * Retirarlo NO despublica lo que ya salio -- eso no esta en nuestras
     * manos -- pero saca la foto de todo lo que este por salir. Por eso
     * tambien limpia las publicaciones que todavia no se publicaron.
     */
    public function updatePhotoConsent(Request $request, ClientPhoto $photo): JsonResponse
    {
        $data = $request->validate(['allowed' => ['required', 'boolean']]);

        $photo->forceFill([
            'marketing_consent_at' => $data['allowed'] ? now() : null,
            'marketing_consent_by_user_id' => $data['allowed'] ? $request->user()->id : null,
        ])->save();

        if (! $data['allowed']) {
            /*
             * Se descartan, no se les quita la foto: una publicacion sin foto
             * volveria a la bandeja como si fuera un error del sistema, y
             * alguien le pondria otra imagen y la sacaria igual. Descartada
             * dice lo que paso.
             */
            SocialPost::where('client_photo_id', $photo->id)
                ->whereIn('status', [
                    SocialPost::STATUS_DRAFT,
                    SocialPost::STATUS_SCHEDULED,
                    SocialPost::STATUS_READY,
                ])
                ->update([
                    'status' => SocialPost::STATUS_DISCARDED,
                    'error' => 'La clienta retiró el permiso para publicar esta foto.',
                ]);
        }

        return response()->json([
            'id' => $photo->id,
            'marketing_consent' => $photo->allowsMarketing(),
        ]);
    }

    public function destroyPhoto(ClientPhoto $photo): JsonResponse
    {
        ImageStorage::delete($photo->image_path);
        $photo->delete();

        return response()->json(['message' => 'Foto eliminada.']);
    }

    /**
     * Lo que resume a un cliente de un vistazo.
     *
     * @return array<string, mixed>
     */
    private function stats(Client $client, string $tz): array
    {
        $completed = Appointment::where('client_id', $client->id)
            ->where('status', Appointment::STATUS_COMPLETED)
            ->get();

        $items = AppointmentItem::whereIn('appointment_id', $completed->pluck('id'))
            ->with(['service', 'resource'])
            ->get();

        $noShows = Appointment::where('client_id', $client->id)
            ->where('status', Appointment::STATUS_NO_SHOW)
            ->count();

        $upcoming = Appointment::where('client_id', $client->id)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        $spent = (float) $completed->sum(fn (Appointment $a) => (float) ($a->total ?? 0));

        return [
            'visits' => $completed->count(),
            'total_spent' => $spent,
            // Sirve para saber si vale la pena un descuento, y cuanto.
            'average_ticket' => $completed->count() > 0 ? round($spent / $completed->count(), 2) : 0.0,
            'no_shows' => $noShows,
            'first_visit' => $completed->min('starts_at')?->setTimezone($tz)->toDateString(),
            'last_visit' => $completed->max('starts_at')?->setTimezone($tz)->toDateString(),
            'favorite_service' => $this->mostCommon($items->pluck('service.name')->filter()),
            'favorite_resource' => $this->mostCommon($items->pluck('resource.name')->filter()),
            'next_appointment' => $upcoming ? [
                'id' => $upcoming->id,
                'starts_at' => $upcoming->starts_at?->setTimezone($tz)->toIso8601String(),
                'label' => $upcoming->starts_at?->setTimezone($tz)->format('d/m/Y H:i'),
            ] : null,
        ];
    }

    /**
     * Historial completo, incluidas cancelaciones e inasistencias.
     *
     * Se muestran a proposito: quien cancela tres veces seguidas es
     * informacion que el mostrador necesita antes de darle la hora pico.
     *
     * @return list<array<string, mixed>>
     */
    private function history(Client $client, string $tz): array
    {
        return Appointment::where('client_id', $client->id)
            ->with(['items.service', 'items.resource', 'paymentMethod'])
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'date' => $a->starts_at?->setTimezone($tz)->format('d/m/Y'),
                'time' => $a->starts_at?->setTimezone($tz)->format('H:i'),
                'starts_at' => $a->starts_at?->setTimezone($tz)->toIso8601String(),
                'status' => $a->status,
                'is_paid' => $a->checked_out_at !== null,
                'total' => $a->total === null ? null : (float) $a->total,
                'payment_method' => $a->paymentMethod?->name,
                'notes' => $a->notes,
                'items' => $a->items->map(fn ($item) => [
                    'id' => $item->id,
                    'service_name' => $item->service?->name,
                    'resource_name' => $item->resource?->name,
                    'final_price' => $item->final_price === null ? null : (float) $item->final_price,
                ]),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function photos(Client $client, string $tz): array
    {
        return $client->photos()->with('appointmentItem.service')->limit(60)->get()
            ->map(fn (ClientPhoto $p) => [
                'id' => $p->id,
                'url' => ImageStorage::url($p->image_path),
                'caption' => $p->caption,
                'date' => $p->taken_at?->setTimezone($tz)->format('d/m/Y'),
                'service_name' => $p->appointmentItem?->service?->name,
                // Lo ve quien mira la ficha, no solo quien arma las
                // publicaciones: es la fila donde se pregunta y se anota.
                'marketing_consent' => $p->allowsMarketing(),
            ])
            ->values()
            ->all();
    }

    /** @param  Collection<int, string>  $values */
    private function mostCommon($values): ?string
    {
        if ($values->isEmpty()) {
            return null;
        }

        return $values->countBy()->sortDesc()->keys()->first();
    }
}
