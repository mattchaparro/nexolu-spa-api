<?php

namespace Tests\Feature\Messaging;

use App\Models\Business;
use App\Models\WhatsappConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La ventana de 24 horas es con UN número del salón.
 *
 * El día que Luxury cambió de número, Marcela le había escrito al de la
 * mañana. El aviso de su cita salió como texto desde el de la tarde --con el
 * que nunca había hablado-- porque el sistema llevaba la ventana por negocio,
 * y Meta lo rechazó (131047).
 */
class VentanaPorNumeroTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const NUMERO_DE_LA_MANANA = '1261384550399714';

    private const NUMERO_DE_LA_TARDE = '1293680153834195';

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = $this->makeBusiness();
        $this->business->forceFill(['whatsapp_phone_number_id' => self::NUMERO_DE_LA_TARDE])->save();
    }

    private function escribioPor(?string $numero, int $haceHoras = 1): WhatsappConversation
    {
        return WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->business->id,
            'phone' => '573142305988',
            'last_message_at' => now()->subHours($haceHoras),
            'last_inbound_at' => now()->subHours($haceHoras),
            'last_inbound_phone_number_id' => $numero,
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);
    }

    public function test_si_escribio_al_numero_de_ahora_la_ventana_esta_abierta(): void
    {
        $this->assertTrue($this->escribioPor(self::NUMERO_DE_LA_TARDE)->windowIsOpen());
    }

    public function test_si_escribio_a_otro_numero_del_salon_no_hay_ventana(): void
    {
        // El caso de Marcela: hace una hora, pero a un número que ya no es.
        $this->assertFalse($this->escribioPor(self::NUMERO_DE_LA_MANANA)->windowIsOpen());
    }

    public function test_si_no_se_sabe_por_que_numero_escribio_se_da_por_cerrada(): void
    {
        /*
         * Las filas de antes de guardar el número. Cerrada: sale la
         * plantilla, que llega siempre. Mandar texto a ciegas es lo que no
         * llega.
         */
        $this->assertFalse($this->escribioPor(null)->windowIsOpen());
    }

    public function test_pasadas_las_24_horas_se_cierra_igual(): void
    {
        $this->assertFalse($this->escribioPor(self::NUMERO_DE_LA_TARDE, haceHoras: 25)->windowIsOpen());
    }

    public function test_el_negocio_sin_numero_propio_sigue_como_antes(): void
    {
        // El número compartido no cambia: ahí la cuenta por negocio es cierta.
        $this->business->forceFill(['whatsapp_phone_number_id' => null])->save();

        $this->assertTrue($this->escribioPor(null)->windowIsOpen());
    }
}
