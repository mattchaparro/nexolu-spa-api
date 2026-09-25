<?php

namespace App\Ai;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\WhatsappConversation;
use App\Services\Ratings\SurveyService;
use App\Support\PublicProfile;

/**
 * La encuesta como formulario de WhatsApp, no como enlace.
 *
 * Al tocar «Calificar servicio» llegaba «Son 30 segundos 👇» y una URL
 * larga, y el enlace no lo abre nadie (Alejandro, 25 de septiembre). El
 * formulario se abre dentro del mismo chat: tres calificaciones con
 * estrellas --cómo la atendieron, cómo quedó el servicio, si fue a tiempo--
 * y un comentario opcional.
 *
 * El contrato con el Flow publicado (docs/whatsapp-flows/encuesta.json) es
 * lo que entrega al enviarse:
 *
 *   { "pedido": "encuesta", "token": "<survey_token>", "atencion": "1".."5",
 *     "resultado": "1".."5", "puntualidad": "1".."5", "comentario"?: "..." }
 *
 * Las respuestas van a SurveyService::record, el mismo lugar donde guarda
 * la encuesta web: una sola definición de qué es una calificación. Sin el
 * Flow publicado (WHATSAPP_SURVEY_FLOW_ID vacío) o si el envío falla, quien
 * llama sigue mandando el enlace de siempre.
 */
final class SurveyForm
{
    private const NOTAS = [
        ['id' => '5', 'title' => '⭐⭐⭐⭐⭐ Excelente'],
        ['id' => '4', 'title' => '⭐⭐⭐⭐ Muy bien'],
        ['id' => '3', 'title' => '⭐⭐⭐ Bien'],
        ['id' => '2', 'title' => '⭐⭐ Regular'],
        ['id' => '1', 'title' => '⭐ Mal'],
    ];

    public static function enabled(): bool
    {
        return trim((string) config('spa.whatsapp_survey_flow_id')) !== '';
    }

    /** @param  array<string, mixed>  $respuesta */
    public static function isOurs(array $respuesta): bool
    {
        return ($respuesta['pedido'] ?? null) === 'encuesta';
    }

    /**
     * Manda el formulario de la visita. False = no salió: quien llama manda
     * el enlace.
     */
    public function send(AiCaller $caller, Appointment $visita): bool
    {
        if (! self::enabled() || $visita->survey_token === null) {
            return false;
        }

        return app(EnvioDirecto::class)->formulario(
            $caller,
            (string) config('spa.whatsapp_survey_flow_id'),
            'ENCUESTA',
            '¡Gracias! 🌟 Cuéntanos cómo te fue, son 3 toques 👇',
            'Calificar',
            [
                'resumen' => $this->resumen($visita),
                'token' => $visita->survey_token,
                'notas' => self::NOTAS,
            ],
        );
    }

    /**
     * Guarda lo que llegó y devuelve el texto para agradecer, o null si el
     * formulario no corresponde a ninguna visita de este negocio.
     *
     * @param  array<string, mixed>  $respuesta
     */
    public function handle(WhatsappConversation $conversacion, array $respuesta): ?string
    {
        $visita = Appointment::withoutGlobalScopes()
            ->where('business_id', $conversacion->business_id)
            ->where('survey_token', (string) ($respuesta['token'] ?? ''))
            ->first();

        if ($visita === null) {
            return null;
        }

        $comentario = trim((string) ($respuesta['comentario'] ?? ''));

        /*
         * Las mismas notas para cada servicio de la visita: el formulario
         * pregunta por la visita entera. Una garantía no se califica (ver
         * SurveyService::form), así que no recibe nota.
         */
        $respuestas = $visita->items()->where('is_warranty', false)->pluck('id')
            ->map(fn (int $itemId) => [
                'item_id' => $itemId,
                'staff_rating' => $respuesta['atencion'] ?? null,
                'service_rating' => $respuesta['resultado'] ?? null,
                'punctuality_rating' => $respuesta['puntualidad'] ?? null,
                'comment' => $comentario !== '' ? $comentario : null,
            ])->all();

        app(SurveyService::class)->record($visita, $respuestas, $respuesta);

        return $this->gracias($visita);
    }

    private function resumen(Appointment $visita): string
    {
        $tz = $visita->business->businessTimezone();
        $dia = $visita->starts_at->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM');
        $items = $visita->items()->with('service', 'resource')->where('is_warranty', false)->get();

        $servicios = $items->map(fn (AppointmentItem $i) => $i->service?->name)->filter()->unique()->implode(' + ');
        $quienes = $items->map(fn (AppointmentItem $i) => $i->resource?->name)->filter()->unique()
            // Solo el nombre de pila: «con Marcela», no «con Marcela Camacho».
            ->map(fn (string $n) => explode(' ', trim($n))[0])->implode(' y ');

        return 'Tu visita del '.$dia
            .($servicios !== '' ? ': '.$servicios : '')
            .($quienes !== '' ? ' con '.$quienes : '')
            .'.';
    }

    /**
     * El gracias, con la reseña de Google si el negocio la tiene.
     *
     * Se ofrece a TODAS, no solo a quien calificó bien: filtrar por nota es
     * «review gating» y Google lo prohíbe (ver SurveyService::form).
     */
    private function gracias(Appointment $visita): string
    {
        $resena = PublicProfile::resolve($visita->business)['google_review_url'] ?? null;

        return '¡Gracias por contarnos! 💖 Tu opinión nos ayuda a mejorar.'
            .($resena ? "\n\nSi quieres, déjanos tu reseña en Google ⭐ ".$resena : '');
    }
}
