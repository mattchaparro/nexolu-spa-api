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
                fn ($q) => $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%");

                    // Solo se busca por telefono si el termino TIENE digitos.
                    // Sin esta guarda, un nombre sin numeros deja la condicion
                    // en LIKE '%%', que matchea a todo cliente con telefono:
                    // buscar "Carolina" devolvia a Laura.
                    $digits = preg_replace('/\D/', '', $term) ?? '';

                    if ($digits !== '') {
                        $sub->orWhere('phone', 'like', "%{$digits}%");
                    }
                }),
                fn ($q) => $q->whereRaw('1 = 0'),
            )
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'last_name', 'phone', 'email']);

        return response()->json(
            $clients->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'last_name' => $c->last_name,
                'full_name' => $c->fullName(),
                'phone' => $c->phone,
                'email' => $c->email,
                // Lo que el desplegable muestra: el telefono es lo que
                // distingue a dos clientes que se llaman igual.
                'label' => trim($c->fullName().($c->phone ? " · {$c->phone}" : '')),
            ])
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
