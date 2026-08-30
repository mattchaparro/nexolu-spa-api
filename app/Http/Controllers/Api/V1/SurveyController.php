<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Services\Ratings\SurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La encuesta que responde el cliente, SIN autenticar.
 *
 * Entra por un token del enlace, no por sesion: quien responde es una clienta
 * con un mensaje de WhatsApp, no alguien con cuenta en el sistema.
 *
 * La validacion es deliberadamente floja. El sistema viejo perdio meses de
 * opiniones porque llegaban y no se guardaban; devolver 422 por un campo que
 * alguien no lleno es la forma mas facil de repetirlo.
 */
class SurveyController
{
    public function __construct(private readonly SurveyService $survey) {}

    private function find(string $token): Appointment
    {
        return Appointment::withoutGlobalScope('business')
            ->where('survey_token', $token)
            ->with(['business', 'items.service', 'items.resource'])
            ->firstOrFail();
    }

    public function show(string $token): JsonResponse
    {
        return response()->json($this->survey->form($this->find($token)));
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $appointment = $this->find($token);

        /*
         * Solo se valida la FORMA, no el contenido: que venga una lista. Las
         * notas fuera de rango se descartan una por una en el servicio y el
         * resto de la respuesta se guarda igual.
         */
        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:20'],
        ]);

        $guardadas = $this->survey->record($appointment, $data['answers'], $request->all());

        return response()->json([
            'saved' => $guardadas,
            'message' => 'Gracias por contarnos cómo te fue.',
        ], 201);
    }
}
