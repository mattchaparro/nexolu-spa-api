<?php

namespace App\Ai;

use App\Ai\Capabilities\AvailabilityCapability;
use App\Ai\Capabilities\CancelAppointmentCapability;
use App\Ai\Capabilities\CreateAppointmentCapability;
use App\Ai\Capabilities\MyAppointmentsCapability;
use App\Ai\Capabilities\SaveContactCapability;
use App\Ai\Capabilities\ServicesCapability;

/**
 * Nombre de herramienta (tal como lo conoce el IA Core, ver apps/spa/tools.py
 * en Nexolu-IA-Core) -> clase que la implementa.
 *
 * Unica fuente de verdad del vocabulario compartido: agregar una capacidad es
 * una entrada mas aqui, nunca una ruta HTTP nueva.
 *
 * Ojo con lo que NO esta: `clientes`. El Core la declara, pero enumerar la
 * base de clientas del negocio por un chat es justo lo que no puede pasar
 * ("son mis clientes y podría robarse los datos"). Mientras no exista aca,
 * invocarla responde 404.
 */
class Registry
{
    /** @var array<string, class-string<Capability>> */
    private const MAP = [
        'guardar_contacto' => SaveContactCapability::class,
        'servicios' => ServicesCapability::class,
        'disponibilidad' => AvailabilityCapability::class,
        'mis_citas' => MyAppointmentsCapability::class,
        'crear_cita' => CreateAppointmentCapability::class,
        'cancelar_cita' => CancelAppointmentCapability::class,
    ];

    public function resolve(string $name): ?Capability
    {
        $class = self::MAP[$name] ?? null;

        return $class ? app($class) : null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Lo que el Core cachea para saber que exige cada herramienta.
     *
     * Una herramienta abierta al publico se publica SIN permiso, y no es un
     * descuido: el Core esconde del modelo las herramientas cuyo permiso no
     * tiene quien pregunta, y una clienta de WhatsApp no tiene NINGUNO. Si
     * `crear_cita` se anunciara pidiendo `citas.crear`, el agente nunca la
     * veria y contestaria "esa herramienta no esta disponible" justo despues
     * de que la clienta dijo "sí, confirmo".
     *
     * El permiso sigue existiendo y sigue exigiendose -- en `invoke()`, para
     * quien SI es empleada. Este catalogo es una ayuda para el modelo, no la
     * frontera de seguridad; esa vive del lado del Spa y no se mueve.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalog(): array
    {
        $catalogo = [];

        foreach (self::MAP as $name => $class) {
            /** @var Capability $capability */
            $capability = app($class);

            $catalogo[$name] = [
                'required_permission' => $capability->allowsCustomers()
                    ? null
                    : $capability->requiredPermission(),
                'required_feature' => $capability->requiredFeature(),
                'allows_customers' => $capability->allowsCustomers(),
            ];
        }

        return $catalogo;
    }
}
