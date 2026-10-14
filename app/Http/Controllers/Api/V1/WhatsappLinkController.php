<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WhatsappLink;
use App\Support\ChannelPhone;
use App\Support\PublicProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Enlaces wa.me con mensaje propio, para pegar en historias, la biografía de
 * Instagram o un anuncio.
 *
 * El enlace lo arma el front con el número que devuelve `index`: aquí solo se
 * guardan el nombre y el mensaje, y se resuelve a qué número apuntan (el
 * «WhatsApp» de la página pública, con el indicativo del país puesto).
 */
class WhatsappLinkController
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $raw = PublicProfile::resolve($business)['whatsapp'] ?? null;

        return response()->json([
            // Solo dígitos con indicativo, o null si el negocio no lo ha cargado.
            'number' => $raw ? ChannelPhone::normalize((string) $raw, $business->country_code ?? 'CO') : null,
            'links' => WhatsappLink::orderBy('name')->get()->map(fn (WhatsappLink $l) => $this->present($l)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $link = WhatsappLink::create([
            'business_id' => $request->user()->business->id,
            ...$this->validated($request),
        ]);

        return response()->json($this->present($link), 201);
    }

    public function update(Request $request, WhatsappLink $link): JsonResponse
    {
        $link->update($this->validated($request));

        return response()->json($this->present($link));
    }

    public function destroy(WhatsappLink $link): JsonResponse
    {
        $link->delete();

        return response()->json(null, 204);
    }

    /** @return array{name: string, message: string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'message' => ['required', 'string', 'max:500'],
        ]);

        return ['name' => trim($data['name']), 'message' => trim($data['message'])];
    }

    /** @return array{id: int, name: string, message: string} */
    private function present(WhatsappLink $link): array
    {
        return ['id' => $link->id, 'name' => $link->name, 'message' => $link->message];
    }
}
