<?php

namespace Tests\Feature\Ai;

use App\Ai\NombreRaro;
use Tests\TestCase;

/**
 * ¿Este nombre parece de persona, o es el perfil de WhatsApp?
 *
 * El nombre de la ficha muchas veces viene del perfil de WhatsApp, y ahí
 * la gente se pone «.», emojis o su negocio. Con eso el bot creía que ya
 * sabía el nombre, no lo preguntaba, y la cita quedaba a nombre de un
 * punto. La regla es conservadora: ante la duda, el nombre vale.
 */
class NombreRaroTest extends TestCase
{
    public function test_nombres_de_persona_pasan(): void
    {
        foreach (['Ana', 'Aleja', 'Mateo Chaparro', "D'Alessandro", 'Ana-María', 'J. Pablo Restrepo'] as $nombre) {
            $this->assertFalse(NombreRaro::es($nombre), "«{$nombre}» debería valer como nombre");
        }
    }

    public function test_perfiles_de_whatsapp_no_pasan(): void
    {
        foreach (['.', '', ' ', '🦋 Princesa 🦋', 'K', 'Nails•Spa', 'Camila 22', '3195244852', '⭐⭐⭐'] as $nombre) {
            $this->assertTrue(NombreRaro::es($nombre), "«{$nombre}» no debería valer como nombre");
        }
    }
}
