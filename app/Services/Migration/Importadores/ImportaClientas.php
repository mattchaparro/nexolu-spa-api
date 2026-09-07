<?php

namespace App\Services\Migration\Importadores;

use App\Models\Client;
use App\Services\Migration\LegacyMap;
use App\Support\ChannelPhone;

/**
 * Las clientas. El activo que de verdad se esta migrando.
 *
 * Dos cosas que no se ven en el esquema y mandan todo el paso:
 *
 * 1. `clients` NO tiene columna de email, pero `clients.metadata` guarda el
 *    suscriptor completo de ManyChat -- email, opt-in de WhatsApp, telefono
 *    con indicativo. La migracion buena lee el JSON, no solo las columnas.
 *
 * 2. Hay fichas repetidas: la misma linea de telefono con dos registros. Se
 *    fusionan por telefono normalizado, y las DOS fichas viejas quedan
 *    apuntando a la misma clienta nueva, para que el historial de ambas
 *    aterrice junto.
 *
 * QUE SE ACTUALIZA en corridas siguientes:
 *
 *   - `accepts_marketing` SIEMPRE, en cuanto la clienta se da de baja alla.
 *     Es lo unico que se pisa sin preguntar: una baja es una decision de la
 *     persona, y seguir escribiendole porque el dato viajo tarde no es un
 *     bug de sincronizacion, es un mensaje que no debio salir.
 *
 *   - Nombre, telefono y email SOLO SI ESTAN VACIOS aca. Durante la
 *     convivencia alguien puede corregir un nombre en el sistema nuevo, y
 *     reimportar cada noche le borraria la correccion.
 */
class ImportaClientas extends Importador
{
    public function nombre(): string
    {
        return 'Clientas';
    }

    public function correr(): void
    {
        $bajas = $this->bajas();

        /* Por id ascendente: cuando dos fichas son la misma persona, la mas
         * antigua crea la clienta y la nueva se le pega. */
        $this->legacy('clients')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(300, function ($filas) use ($bajas) {
                foreach ($filas as $fila) {
                    $this->una($fila, $bajas);
                }
            });
    }

    /** @param array<string, true> $bajas */
    private function una(object $fila, array $bajas): void
    {
        $meta = json_decode((string) ($fila->metadata ?? ''), true);
        $meta = is_array($meta) ? $meta : [];

        $this->enderezarVolteada($fila);

        $telefono = $this->telefono($fila, $meta);
        $acepta = $this->acepta($telefono, $meta, $bajas);

        $huella = LegacyMap::huella([
            $fila->name, $fila->first_name, $fila->last_name,
            $telefono, $meta['email'] ?? null, $acepta,
        ]);

        $idNuevo = $this->map->idNuevo('client', $fila->id);

        if ($idNuevo !== null) {
            $this->sincronizar((int) $fila->id, $idNuevo, $fila, $meta, $telefono, $acepta, $huella);

            return;
        }

        /* ¿Otra ficha vieja con este mismo telefono ya creo la clienta? */
        if ($telefono !== null) {
            $gemela = $this->porTelefono($telefono);

            if ($gemela !== null) {
                $this->anotar('client', (int) $fila->id, $gemela, $huella);
                $this->reporte->aviso(
                    'Clientas',
                    "La ficha {$fila->id} comparte el telefono {$telefono} con otra: se fusionaron.",
                );

                return;
            }
        }

        [$nombre, $apellido] = $this->partirNombre($fila);

        $id = $this->crear(fn () => Client::create([
            'business_id' => $this->business->id,
            'name' => $nombre,
            'last_name' => $apellido,
            'phone' => $telefono,
            'email' => $this->email($meta),
            'gender' => $this->genero($fila->gender ?? null),
            'accepts_marketing' => $acepta,
            'is_active' => true,
        ])->id);

        $this->anotar('client', (int) $fila->id, $id, $huella);
        $this->reporte->creado('Clientas');

        if ($telefono === null) {
            $this->reporte->aviso(
                'Clientas',
                "La ficha {$fila->id} ({$fila->name}) no tiene telefono utilizable: no recibira recordatorios.",
            );
        }
    }

    /** @param array<string, mixed> $meta */
    private function sincronizar(
        int $legacyId,
        int $idNuevo,
        object $fila,
        array $meta,
        ?string $telefono,
        bool $acepta,
        string $huella,
    ): void {
        if (! $this->map->cambio('client', $legacyId, $huella)) {
            $this->reporte->saltado('Clientas');

            return;
        }

        if (! $this->simular) {
            $cliente = Client::withoutGlobalScope('business')->find($idNuevo);

            if ($cliente === null) {
                // La borraron aca. Volver a crearla seria resucitar a alguien
                // que alguien decidio quitar: se avisa y no se toca.
                $this->reporte->aviso('Clientas', "La clienta {$idNuevo} (legacy {$legacyId}) ya no existe aca.");

                return;
            }

            $cambios = ['accepts_marketing' => $acepta];

            // Lo demas solo rellena huecos: nunca pisa lo que hay escrito.
            if (blank($cliente->phone) && $telefono !== null) {
                $cambios['phone'] = $telefono;
            }

            if (blank($cliente->email) && $this->email($meta) !== null) {
                $cambios['email'] = $this->email($meta);
            }

            $cliente->update($cambios);
        }

        $this->anotar('client', $legacyId, $idNuevo, $huella);
        $this->reporte->actualizado('Clientas');
    }

    /**
     * Endereza las fichas con el nombre y el telefono al reves.
     *
     * Pasa de verdad: hay fichas con name="3106985459" y cellphone="Luisa".
     * Alguien se equivoco de campo al crearlas. Sin esto, esas clientas
     * llegan sin telefono -- nunca reciben un recordatorio -- y con un numero
     * por nombre, que es lo que verian en el saludo de WhatsApp.
     *
     * La regla es estrecha a proposito: solo se voltea si el NOMBRE se lee
     * como telefono y el TELEFONO no. Cualquier duda, se deja como esta.
     */
    private function enderezarVolteada(object $fila): void
    {
        if (ChannelPhone::normalize((string) ($fila->name ?? '')) === null) {
            return;
        }

        if (ChannelPhone::normalize((string) ($fila->cellphone ?? '')) !== null) {
            return;
        }

        if (trim((string) ($fila->cellphone ?? '')) === '') {
            return;
        }

        [$fila->name, $fila->cellphone] = [$fila->cellphone, $fila->name];
        $fila->first_name = null;
        $fila->last_name = null;

        $this->reporte->aviso(
            'Clientas',
            "La ficha {$fila->id} tenia el nombre y el telefono intercambiados: se enderezo.",
        );
    }

    /**
     * El telefono, mirando primero el JSON de ManyChat.
     *
     * `cellphone` trae 10 digitos sin indicativo; el JSON trae el numero como
     * lo tiene WhatsApp, ya con el 57. Se prefiere el segundo porque es el
     * que de verdad recibe mensajes.
     *
     * @param  array<string, mixed>  $meta
     */
    private function telefono(object $fila, array $meta): ?string
    {
        foreach ([$meta['whatsapp_phone'] ?? null, $meta['phone'] ?? null, $fila->cellphone ?? null] as $bruto) {
            if (! is_string($bruto) || trim($bruto) === '') {
                continue;
            }

            $normalizado = ChannelPhone::normalize($bruto);

            if ($normalizado !== null) {
                return $normalizado;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $meta */
    private function email(array $meta): ?string
    {
        $email = $meta['email'] ?? null;

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Los telefonos que pidieron no recibir mas mensajes.
     *
     * @return array<string, true>
     */
    private function bajas(): array
    {
        $lista = [];

        foreach ($this->legacy('unsuscribe_contacts')->whereNull('deleted_at')->get() as $fila) {
            $numero = ChannelPhone::normalize((string) $fila->whatsapp_number);

            if ($numero !== null) {
                $lista[$numero] = true;
            }
        }

        return $lista;
    }

    /**
     * Si se le puede escribir para difusiones.
     *
     * Cerrado por defecto: sin opt-in explicito, no. Una lista de difusion
     * que arranca asumiendo el si es la forma mas rapida de que el numero de
     * WhatsApp del negocio termine bloqueado.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, true>  $bajas
     */
    private function acepta(?string $telefono, array $meta, array $bajas): bool
    {
        if ($telefono !== null && isset($bajas[$telefono])) {
            return false;
        }

        $optin = $meta['optin_whatsapp'] ?? null;

        return $optin === true || $optin === 1 || $optin === '1';
    }

    /**
     * Nombre y apellido, con el caso feo del legacy contemplado.
     *
     * Unas fichas tienen `first_name`/`last_name` separados y otras traen el
     * nombre completo metido en `name`. Si no hay apellido, se parte `name`
     * por el primer espacio.
     *
     * @return array{0:string, 1:?string}
     */
    private function partirNombre(object $fila): array
    {
        $nombre = trim((string) ($fila->first_name ?: $fila->name ?: ''));
        $apellido = trim((string) ($fila->last_name ?? ''));

        if ($apellido === '' && $fila->first_name === null && str_contains($nombre, ' ')) {
            [$nombre, $apellido] = explode(' ', $nombre, 2);
        }

        return [$nombre !== '' ? $nombre : 'Sin nombre', $apellido !== '' ? $apellido : null];
    }

    private function genero(?string $bruto): ?string
    {
        return match (strtoupper((string) $bruto)) {
            'F', 'FEMALE', 'FEMENINO' => 'female',
            'M', 'MALE', 'MASCULINO' => 'male',
            default => null,
        };
    }

    private function porTelefono(string $telefono): ?int
    {
        if ($this->simular) {
            // En simulacion no hay filas nuevas que consultar; la deteccion
            // real de gemelas se ve en la corrida de verdad.
            return null;
        }

        return Client::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->where('phone', $telefono)
            ->value('id');
    }

    private function anotar(string $entidad, int $legacyId, int $nuevoId, ?string $huella = null): void
    {
        $this->simular
            ? $this->map->anotarEnMemoria($entidad, $legacyId, $nuevoId, $huella)
            : $this->map->anotar($entidad, $legacyId, $nuevoId, $huella);
    }
}
