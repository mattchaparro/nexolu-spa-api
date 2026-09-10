<?php

namespace Tests\Unit;

use App\Support\Ratings\Comentario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Que "No" no llegue a la pantalla de nadie, y que "La mejor" si.
 *
 * Los casos son textuales de la base de Luxury: 43 de sus 60 comentarios son
 * cortesia y 10 son opiniones de verdad.
 */
class ComentarioTest extends TestCase
{
    #[DataProvider('cortesias')]
    public function test_la_cortesia_no_es_opinion(string $texto): void
    {
        $this->assertFalse(Comentario::esOpinion($texto), $texto);
    }

    public static function cortesias(): array
    {
        return array_map(fn ($t) => [$t], [
            'no',
            'No',
            'NO',
            'no gracias',
            'No, gracias',
            'no !gracias!',
            'no , gracias 🫂',
            'No, muchas gracias 🙏',
            'gracias',
            '¡Gracias! 😊',
            'Ninguno',
            'ok',
            '   ',
            '🙏',
        ]);
    }

    #[DataProvider('opiniones')]
    public function test_una_opinion_se_conserva(string $texto): void
    {
        $this->assertTrue(Comentario::esOpinion($texto), $texto);
    }

    public static function opiniones(): array
    {
        return array_map(fn ($t) => [$t], [
            // Ocho letras, y es justo lo que alguien quiere leer: por eso no
            // se filtra por largo.
            'La mejor',
            'Marcela es increíble, trabaja precioso',
            'Excelente trabajo, muy contenta 🌻10/10☺️',
            'La manicurista es muy wapa creo q me voy a enamorar',
            // Una queja tambien es una opinion, y de las utiles.
            'Me gustaría tener bebidas calientes',
            // Corta y positiva: no esta en la lista de cortesia a proposito.
            'Todo bien',
        ]);
    }

    public function test_sin_comentario_no_hay_opinion(): void
    {
        $this->assertFalse(Comentario::esOpinion(null));
        $this->assertFalse(Comentario::esOpinion(''));
    }
}
