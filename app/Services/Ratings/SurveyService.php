<?php

namespace App\Services\Ratings;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\ServiceRating;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La encuesta que se manda cuando termina el servicio.
 *
 * La regla que gobierna todo este archivo: UNA RESPUESTA QUE LLEGA NO SE
 * PIERDE. El sistema viejo tiene un comando que recupera calificaciones
 * parseando los logs de Laravel, porque llegaban, se logueaban y no se
 * guardaban. Aca se guarda primero lo que vino y se interpreta despues.
 */
class SurveyService
{
    /**
     * El token del enlace de la encuesta, creandolo si no existe.
     *
     * Aleatorio y no el id de la cita: un enlace con el id deja calificar las
     * citas de otros probando numeros.
     */
    public function tokenFor(Appointment $appointment): string
    {
        if ($appointment->survey_token === null) {
            $appointment->forceFill(['survey_token' => Str::random(48)])->save();
        }

        return $appointment->survey_token;
    }

    /** Marca que la encuesta se envio. */
    public function markSent(Appointment $appointment): void
    {
        $appointment->forceFill([
            'survey_token' => $this->tokenFor($appointment),
            'survey_sent_at' => now(),
        ])->save();
    }

    /**
     * A quien hay que calificar en esa visita.
     *
     * Una por PERSONA y no una por visita: si dos personas atendieron, el
     * trabajo de una pudo quedar bien y el de la otra no, y un promedio de las
     * dos no le sirve a nadie.
     *
     * @return array<string, mixed>
     */
    public function form(Appointment $appointment): array
    {
        $items = $appointment->items()->with(['service', 'resource'])->get();

        return [
            'business' => [
                'name' => $appointment->business->name,
                /*
                 * Se ofrece a TODO el que responde, no solo a quien calificó
                 * bien. Filtrar por nota se llama "review gating" y las
                 * políticas de Google lo prohíben: puede costarle la ficha
                 * entera al negocio. Las notas bajas sirven para llamar a esa
                 * persona, no para esconderla.
                 */
                'google_review_url' => \App\Support\PublicProfile::resolve($appointment->business)['google_review_url'] ?? null,
            ],
            'date_label' => $appointment->starts_at
                ->setTimezone($appointment->business->businessTimezone())
                ->translatedFormat('l j \d\e F'),
            'answered' => $appointment->survey_answered_at !== null,
            'items' => $items
                // Una garantia no se califica: la visita existe justamente
                // porque algo salio mal, y pedir estrellas ahi es preguntar
                // por el clavo en la herida.
                ->reject(fn (AppointmentItem $i) => $i->is_warranty)
                ->map(fn (AppointmentItem $i) => [
                    'item_id' => $i->id,
                    'service_name' => $i->service?->name,
                    'resource_name' => $i->resource?->name,
                ])->values()->all(),
        ];
    }

    /**
     * Guarda la respuesta.
     *
     * Guarda AUNQUE algo no cuadre: una calificacion que no se puede atribuir
     * a una linea igual se persiste con su payload crudo. Perder la opinion es
     * peor que tenerla sin dueno, y una fila huerfana se revisa a mano.
     *
     * @param  array<int, array<string, mixed>>  $answers
     */
    public function record(Appointment $appointment, array $answers, array $raw): int
    {
        return DB::transaction(function () use ($appointment, $answers, $raw) {
            $itemIds = $appointment->items()->pluck('resource_id', 'id');
            $guardadas = 0;

            foreach ($answers as $answer) {
                $itemId = isset($answer['item_id']) ? (int) $answer['item_id'] : null;
                // Solo se acepta un item DE ESTA cita: sin eso, una respuesta
                // podria colgarle una calificacion a la visita de otro.
                $valido = $itemId !== null && $itemIds->has($itemId);

                ServiceRating::updateOrCreate(
                    [
                        'appointment_id' => $appointment->id,
                        'appointment_item_id' => $valido ? $itemId : null,
                    ],
                    [
                        'business_id' => $appointment->business_id,
                        'resource_id' => $valido ? $itemIds->get($itemId) : null,
                        'client_id' => $appointment->client_id,
                        'service_rating' => $this->score($answer['service_rating'] ?? null),
                        'staff_rating' => $this->score($answer['staff_rating'] ?? null),
                        'punctuality_rating' => $this->score($answer['punctuality_rating'] ?? null),
                        'comment' => isset($answer['comment']) ? mb_substr((string) $answer['comment'], 0, 2000) : null,
                        // Lo que llego tal cual, por si algo no se supo leer.
                        'raw_payload' => $raw,
                    ],
                );

                $guardadas++;
            }

            $appointment->forceFill(['survey_answered_at' => now()])->save();

            return $guardadas;
        });
    }

    /**
     * Una nota valida, o null.
     *
     * Fuera de rango se descarta EN SILENCIO en vez de rechazar la respuesta
     * entera: el resto de lo que la persona escribio sigue valiendo.
     */
    private function score(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $score = (int) $value;

        return $score >= 1 && $score <= 5 ? $score : null;
    }
}
