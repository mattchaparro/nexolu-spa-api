<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\Client;
use App\Support\ChannelPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Identificar a quien se tiene delante, sin abrir la base de clientas.
 *
 * EL PROBLEMA QUE RESUELVE. De 3.331 visitas cobradas, 2.112 no tienen ficha
 * -- el 63%. Sin ficha no hay sello, ni encuesta, ni historial: por eso hay
 * exactamente 1.219 sellos, que son las 1.219 visitas que si la tienen. El
 * sistema viejo lo anotaba (674 fallos de "no se encontro cliente", 45 al mes,
 * todavia ocurriendo) y nadie leia ese log.
 *
 * LA TENSION. El dueño no quiere que su equipo pueda ver ni llevarse su base
 * de clientas: es el activo del negocio, y una manicurista con la lista puede
 * atenderlas por fuera. Pero para que la visita cuente, alguien tiene que
 * poder decir quien vino.
 *
 * COMO SE RESUELVE. Por la forma de la busqueda, no por el permiso:
 *
 *   - Se pregunta por un TELEFONO COMPLETO, no por un prefijo ni por un
 *     nombre. Quien tiene el numero ya conoce a la persona.
 *   - Se responde de a UNA. No hay listado que recorrer.
 *   - La respuesta trae el nombre de pila y la inicial del apellido, y NUNCA
 *     el telefono ni el correo: sirve para confirmar ("¿Laura B.?") y no para
 *     copiar.
 *
 * Con eso, el buscador contesta "¿usted ya esta registrada?" y no sirve para
 * llevarse nada.
 */
class ClientLookupController
{
    /** Buscar por telefono completo. */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Minimo siete digitos: con menos es un prefijo, y un prefijo
            // convierte esto en el listado que no queremos.
            'phone' => ['required', 'string', 'min:7', 'max:32'],
        ]);

        $telefono = ChannelPhone::normalize($data['phone'], $request->user()->business->country_code);
        $digitos = preg_replace('/\D/', '', $data['phone']) ?? '';

        if (mb_strlen($digitos) < 7) {
            return response()->json(['found' => false]);
        }

        /*
         * Se compara por los ULTIMOS digitos y no por igualdad exacta: la
         * misma clienta esta guardada como "3001234567", "573001234567" y
         * "+57 300 123 4567" segun quien la anoto. Exigir el formato exacto
         * haria que el buscador dijera "no existe" y se crearan fichas
         * repetidas de la misma persona, que es peor que no buscar.
         */
        $cola = mb_substr($digitos, -10);

        $cliente = Client::query()
            ->where('is_active', true)
            ->whereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$cola}"])
            ->orderBy('id')
            ->first();

        if ($cliente === null) {
            return response()->json(['found' => false, 'normalized' => $telefono]);
        }

        return response()->json([
            'found' => true,
            'client' => $this->identidad($cliente),
        ]);
    }

    /** Crear una ficha con lo minimo, cuando no existe. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'string', 'min:7', 'max:32'],
        ]);

        $business = $request->user()->business;

        $cliente = Client::create([
            'business_id' => $business->id,
            'name' => trim($data['name']),
            'phone' => ChannelPhone::normalize($data['phone'], $business->country_code),
            'is_active' => true,
            /*
             * SIN marketing por defecto.
             *
             * Quien deja su numero para que le cuenten los sellos no esta
             * pidiendo promociones. Encenderlo solo, y ademas desde una
             * pantalla de cobro donde nadie le pregunto, es justo lo que la ley
             * de datos no permite.
             */
            'accepts_marketing' => false,
        ]);

        return response()->json(['client' => $this->identidad($cliente)], 201);
    }

    /**
     * Colgar la ficha a una cita que se cobro sin ella.
     *
     * Solo mientras NO se haya cobrado. Despues del cobro los totales, la
     * comision y los sellos ya se calcularon: cambiar de quien es la visita
     * despues obligaria a rehacerlos, y esa es otra funcion.
     */
    public function attach(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
        ]);

        if ($appointment->checked_out_at !== null) {
            return response()->json([
                'message' => 'Esa cita ya se cobró. Los sellos y la comisión se calcularon sin ficha.',
            ], 422);
        }

        // Dentro del scope del negocio: un id ajeno simplemente no existe.
        $cliente = Client::findOrFail($data['client_id']);

        $appointment->forceFill([
            'client_id' => $cliente->id,
            // El nombre suelto de la cita pasa a ser el de la ficha: dejar dos
            // nombres distintos para la misma visita confunde a quien la mire
            // despues en la agenda.
            'client_name' => $cliente->fullName(),
        ])->save();

        return response()->json(['client' => $this->identidad($cliente)]);
    }

    /**
     * Lo MINIMO para reconocer a alguien: nombre de pila e inicial.
     *
     * Sin telefono y sin correo a proposito. Quien pregunta ya tiene el
     * numero -- lo acaba de escribir -- asi que devolverselo no le dice nada
     * nuevo, y devolver el correo si le daria algo que no tenia.
     *
     * @return array<string, mixed>
     */
    private function identidad(Client $cliente): array
    {
        $inicial = mb_substr(trim((string) $cliente->last_name), 0, 1);

        return [
            'id' => $cliente->id,
            'display_name' => trim($cliente->name.($inicial !== '' ? " {$inicial}." : '')),
        ];
    }
}
