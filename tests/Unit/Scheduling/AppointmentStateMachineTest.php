<?php

namespace Tests\Unit\Scheduling;

use App\Models\Appointment;
use App\Support\Scheduling\AppointmentStateMachine as SM;
use PHPUnit\Framework\TestCase;

/**
 * Las transiciones legales de una cita.
 *
 * Es una tabla, y por eso se prueba como una tabla. Lo que defiende es que
 * ningun camino raro deje plata mal contada: una cita cancelada que reaparece
 * como cobrada la suma el cierre del dia.
 */
class AppointmentStateMachineTest extends TestCase
{
    public function test_el_camino_normal_de_una_cita(): void
    {
        $this->assertTrue(SM::canTransition(Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED));
        $this->assertTrue(SM::canTransition(Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS));
        $this->assertTrue(SM::canTransition(Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_COMPLETED));
    }

    public function test_se_puede_cobrar_sin_pasar_por_confirmada(): void
    {
        // Llegó, se atendió y se cobró. Nadie tocó "confirmar" en el camino, y
        // obligar a hacerlo solo produciría clics de ritual.
        $this->assertTrue(SM::canTransition(Appointment::STATUS_PENDING, Appointment::STATUS_COMPLETED));
    }

    public function test_una_cita_cancelada_es_terminal(): void
    {
        $this->assertTrue(SM::isTerminal(Appointment::STATUS_CANCELLED));

        foreach (Appointment::statuses() as $destino) {
            if ($destino === Appointment::STATUS_CANCELLED) {
                continue;
            }

            $this->assertFalse(
                SM::canTransition(Appointment::STATUS_CANCELLED, $destino),
                "Cancelada no deberia poder pasar a {$destino}",
            );
        }
    }

    public function test_deshacer_un_cobro_devuelve_a_confirmada_y_nada_mas(): void
    {
        $this->assertSame(
            [Appointment::STATUS_CONFIRMED],
            SM::allowedFrom(Appointment::STATUS_COMPLETED),
        );

        // Volver a "sin confirmar" perdería que la clienta sí vino.
        $this->assertFalse(SM::canTransition(Appointment::STATUS_COMPLETED, Appointment::STATUS_PENDING));
        // Y cancelar algo ya cobrado dejaría un cobro sin cita.
        $this->assertFalse(SM::canTransition(Appointment::STATUS_COMPLETED, Appointment::STATUS_CANCELLED));
    }

    public function test_no_se_marca_inasistencia_de_algo_que_ya_empezo(): void
    {
        $this->assertFalse(SM::canTransition(Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_NO_SHOW));
    }

    public function test_una_inasistencia_marcada_por_error_se_revierte(): void
    {
        // Llegó tarde y alguien ya la había marcado. La ocupación nunca se
        // liberó, así que el hueco sigue siendo suyo.
        $this->assertTrue(SM::canTransition(Appointment::STATUS_NO_SHOW, Appointment::STATUS_CONFIRMED));
        $this->assertTrue(SM::canTransition(Appointment::STATUS_NO_SHOW, Appointment::STATUS_IN_PROGRESS));

        // Pero no se cobra directo desde ahí: primero se reconoce que sí vino.
        $this->assertFalse(SM::canTransition(Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED));
    }

    public function test_quedarse_en_el_mismo_estado_es_legal(): void
    {
        // Un negocio puede tener dos etapas propias que apunten al mismo estado
        // núcleo ("Confirmada por WhatsApp" y "Confirmada por teléfono").
        // Moverse entre ellas no es un error.
        foreach (Appointment::statuses() as $estado) {
            $this->assertTrue(SM::canTransition($estado, $estado));
        }
    }

    public function test_la_tabla_cubre_todos_los_estados(): void
    {
        // Un estado sin fila haría que allowedFrom() devuelva vacío y todo
        // salto desde ahí se rechace sin que nadie entienda por qué.
        $this->assertEqualsCanonicalizing(
            Appointment::statuses(),
            array_keys(SM::transitions()),
        );
    }

    public function test_ningun_destino_apunta_a_un_estado_inexistente(): void
    {
        foreach (SM::transitions() as $desde => $destinos) {
            foreach ($destinos as $destino) {
                $this->assertContains(
                    $destino,
                    Appointment::statuses(),
                    "{$desde} apunta a {$destino}, que no existe",
                );
            }
        }
    }

    public function test_el_motivo_del_rechazo_explica_que_hacer(): void
    {
        $this->assertNull(SM::reasonToRefuse(
            Appointment::STATUS_PENDING,
            Appointment::STATUS_CONFIRMED,
        ));

        $this->assertStringContainsString(
            'agéndala de nuevo',
            SM::reasonToRefuse(Appointment::STATUS_CANCELLED, Appointment::STATUS_CONFIRMED),
        );

        $this->assertStringContainsString(
            'deshaz el cobro',
            SM::reasonToRefuse(Appointment::STATUS_COMPLETED, Appointment::STATUS_CANCELLED),
        );

        $this->assertStringContainsString(
            'ya empezó',
            SM::reasonToRefuse(Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_NO_SHOW),
        );
    }
}
