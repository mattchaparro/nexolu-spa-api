<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use App\Support\ChannelPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientController
{
    /**
     * Busca clientes por nombre o telefono.
     *
     * Exige al menos dos caracteres a proposito: sin eso, el buscador del
     * mostrador se convierte en un volcado completo de la base de clientes
     * con sus telefonos ante cualquier peticion vacia.
     */
    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $clients = Client::query()
            ->where('is_active', true)
            ->when(
                mb_strlen($term) >= 2,
                /*
                 * Palabra por palabra: CADA una tiene que estar en el nombre,
                 * el apellido o el teléfono. Antes se buscaba el término
                 * entero en un solo campo, y «Carolina Pér» -- que es como se
                 * escribe en el mostrador -- no encontraba a Carolina Pérez:
                 * «Carolina» está en el nombre y «Pér» en el apellido.
                 */
                function ($q) use ($term) {
                    /*
                     * Sin la bandera /u: los espacios son ASCII, y con /u un
                     * término mal codificado hacía fallar la división -- sin
                     * palabras no quedaba ningún filtro y salían TODAS.
                     */
                    $palabras = array_values(array_filter(preg_split('/\s+/', $term) ?: []));

                    if ($palabras === []) {
                        $q->whereRaw('1 = 0');
                    }

                    foreach ($palabras as $palabra) {
                        $q->where(function ($sub) use ($palabra) {
                            $sub->where('name', 'like', "%{$palabra}%")
                                ->orWhere('last_name', 'like', "%{$palabra}%");

                            // Solo se busca por telefono si la palabra TIENE
                            // digitos. Sin esta guarda, un nombre sin numeros
                            // deja la condicion en LIKE '%%', que matchea a
                            // todo cliente con telefono: buscar "Carolina"
                            // devolvia a Laura.
                            $digits = preg_replace('/\D/', '', $palabra) ?? '';

                            if ($digits !== '') {
                                $sub->orWhere('phone', 'like', "%{$digits}%");
                            }
                        });
                    }
                },
                fn ($q) => $q->whereRaw('1 = 0'),
            )
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'last_name', 'phone', 'email']);

        /*
         * Quien atiende (sin `clientes.ver`) busca por nombre o teléfono y
         * elige, pero no se lleva el número: le llegan solo los últimos
         * cuatro dígitos, que alcanzan para distinguir a dos Carolinas y no
         * para armarse una lista de contactos.
         */
        $completo = $request->user()->hasBusinessPermission('clientes.ver');

        return response()->json(
            $clients->map(function (Client $c) use ($completo) {
                $telefono = $completo
                    ? $c->phone
                    : ($c->phone ? '··· '.substr(preg_replace('/\D/', '', $c->phone), -4) : null);

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'last_name' => $c->last_name,
                    'full_name' => $c->fullName(),
                    'phone' => $telefono,
                    'email' => $completo ? $c->email : null,
                    // Lo que el desplegable muestra: el telefono es lo que
                    // distingue a dos clientes que se llaman igual.
                    'label' => trim($c->fullName().($telefono ? " · {$telefono}" : '')),
                ];
            })
        );
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! empty($data['phone'])) {
            $phone = ChannelPhone::normalize($data['phone'], $business->country_code);

            if ($phone === null) {
                throw ValidationException::withMessages([
                    'phone' => ['Ese número no parece válido.'],
                ]);
            }

            // El indice unico (business_id, phone) lo impediria de todos
            // modos, pero un 422 con el nombre de quien ya lo tiene es mas
            // util que un 500.
            $existing = Client::where('phone', $phone)->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'phone' => ["Ese número ya es de {$existing->fullName()}."],
                ]);
            }

            $data['phone'] = $phone;
        }

        $client = Client::create($data + ['business_id' => $business->id]);

        return response()->json([
            'id' => $client->id,
            'full_name' => $client->fullName(),
            'phone' => $client->phone,
            'label' => trim($client->fullName().($client->phone ? " · {$client->phone}" : '')),
        ], 201);
    }
}
