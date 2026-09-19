<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Services\ClientResolver;

/**
 * Guardar quien es el numero que escribe.
 *
 * Existe por una razon de negocio, no de conversacion: hasta ahora la ficha
 * solo nacia al AGENDAR, asi que todo el que escribia y no cerraba una cita
 * se perdia. Y sin ficha no hay a quien mandarle una promocion despues -- la
 * base de clientas es el activo del local, y se construye con cada persona
 * que escribe, no solo con las que agendan.
 *
 * Lo llama el agente en cuanto sabe el nombre. Guardar la ficha NO es un
 * paso de la conversacion: es un efecto de lado. Por eso el resultado
 * siempre lo empuja a seguir en el MISMO turno -- cuando esto devolvia solo
 * `guardado: true`, el modelo lo leia como "ya hice algo" y se quedaba
 * esperando, preguntando el dia que ya le habian dicho.
 */
class SaveContactCapability implements Capability
{
    public function __construct(private readonly ClientResolver $clients) {}

    public function requiredPermission(): ?string
    {
        return null;
    }

    public function requiredFeature(): ?string
    {
        return 'clients';
    }

    public function allowsCustomers(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        // Una empleada no "se registra" a si misma por este canal.
        if ($caller->isStaff()) {
            return ['guardado' => false, 'motivo' => 'Esta capacidad es para clientas.'];
        }

        $nombre = trim($arguments['nombre']);

        /*
         * Si la ficha YA tiene nombre, no se pisa.
         *
         * El del sistema lo escribio el negocio, con la ortografia que usa en
         * su agenda; el de aca lo dedujo un modelo de una frase suelta. Ante
         * la duda gana el del negocio -- y de todas formas ya no hacia falta
         * preguntarlo, porque el perfil de la conversacion se lo dice al
         * agente.
         */
        if ($caller->client !== null) {
            return [
                'guardado' => false,
                'ya_lo_teniamos' => $caller->client->fullName(),
                'instruccion' => 'Ya sabías su nombre. Úsalo y sigue con la cita. '
                    .self::SIGUE_AHORA,
            ];
        }

        $ficha = $this->clients->resolve(
            $caller->business->id,
            null,
            $nombre,
            $caller->phone,
        );

        return [
            'guardado' => $ficha !== null,
            'nombre' => $ficha?->fullName(),
            'instruccion' => self::SIGUE_AHORA,
        ];
    }

    /**
     * Lo que tiene que pasar DESPUES de guardar, y que el modelo se saltaba.
     *
     * La ficha no le sirve de nada a quien escribe: lo que esperaba era que
     * le dijeran a que horas hay. Guardar y quedarse callado le cuesta un
     * mensaje de ida y vuelta a una persona que ya dijo todo lo que hacia
     * falta.
     */
    private const SIGUE_AHORA = 'Esto no fue una respuesta para ella: no le escribas todavía. '
        .'Si ya sabes qué servicio quiere y qué día, llama YA a `disponibilidad` en este '
        .'mismo turno. Solo si de verdad te falta uno de esos dos datos, pregúntalo.';
}
