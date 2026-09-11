<?php

namespace Tests\Unit;

use App\Support\NombreDePila;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Que "Hola ., gracias por venir tanto" no le llegue a nadie.
 *
 * Los casos son textuales de la base de Luxury: durante años el nombre de la
 * clienta lo puso ManyChat, que manda el nombre de usuario de WhatsApp. De sus
 * 759 fichas, 120 tienen un nombre inservible -- una se llama ".". En una
 * difusión a 500 personas ese error sale 120 veces.
 */
class NombreDePilaTest extends TestCase
{
    #[DataProvider('noSonNombres')]
    public function test_lo_que_no_es_un_nombre_no_saluda(string $texto): void
    {
        $this->assertNull(NombreDePila::deSaludo($texto), $texto);
    }

    public static function noSonNombres(): array
    {
        return array_map(fn ($t) => [$t], [
            '.',
            '..',
            '♡.',
            '🌸',
            '🦋🌻',
            '🥰🥰🥰',
            '𝓐𝓵𝓮𝓳𝓪𝓷𝓭𝓻𝓪',
            '✨Andrea',
            '~karoll',
            '$@M&R 😎',
            'Cc',
            'Bb',
            '',
            '   ',
            // Alguien tecleó un mensaje en el campo del nombre.
            'Disculpa no hay más horas disponibles',
            'Cliente',
            'sin nombre',
        ]);
    }

    #[DataProvider('siSonNombres')]
    public function test_un_nombre_de_verdad_saluda(string $texto, string $esperado): void
    {
        $this->assertSame($esperado, NombreDePila::deSaludo($texto));
    }

    public static function siSonNombres(): array
    {
        return [
            // Sólo el primer nombre: "Hola Maria Fernanda Restrepo" no lo
            // escribe nadie por WhatsApp.
            ['Maria Fernanda Restrepo', 'Maria'],
            ['Laura', 'Laura'],
            // Tres letras alcanzan. No se filtra por largo: "Ana" es un
            // nombre y "🌸Dannita🌸" tiene once caracteres y no lo es.
            ['Ana', 'Ana'],
            ['Sofía Rodríguez', 'Sofía'],
            ['Yeimy Angel', 'Yeimy'],
            ["O'Brien", "O'Brien"],
        ];
    }

    public function test_el_saludo_resuelve_la_coma(): void
    {
        /*
         * Se devuelve el saludo entero y no sólo el nombre para que quien
         * escribe la plantilla no tenga que resolverlo: "Hola {nombre}," con
         * el nombre vacío deja "Hola ,".
         */
        $this->assertSame('Hola Laura', NombreDePila::saludo('Laura Bello'));
        $this->assertSame('Hola', NombreDePila::saludo('🌸'));
        $this->assertSame('Buenas', NombreDePila::saludo('.', 'Buenas'));
    }
}
