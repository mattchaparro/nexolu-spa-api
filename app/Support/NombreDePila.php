<?php

namespace App\Support;

/**
 * El nombre con el que se puede saludar a alguien, o nada.
 *
 * EL PROBLEMA. Durante años el nombre de la clienta lo ponia ManyChat, y
 * ManyChat manda el nombre de usuario de WhatsApp: emojis, una letra, un
 * punto, letras decorativas. De las 759 fichas de Luxury, 120 tienen un nombre
 * inservible -- una se llama literalmente ".".
 *
 * Mandarle "Hola ., gracias por venir tanto" a una clienta es peor que no
 * saludarla por su nombre: parece un error del sistema y lo es. Y en una
 * difusion a 500 personas, ese error sale 120 veces.
 *
 * QUE CUENTA COMO NOMBRE. Solo letras, espacios, apostrofes y guiones. No se
 * filtra por largo: "Ana" tiene tres letras y es un nombre; "🌸Dannita🌸" tiene
 * once caracteres y no lo es.
 *
 * Devuelve SOLO EL PRIMER NOMBRE: "Hola Maria Fernanda Restrepo" no lo escribe
 * nadie por WhatsApp.
 */
final class NombreDePila
{
    /**
     * Palabras que no son un nombre aunque esten escritas con letras.
     *
     * Salen de los datos: alguien tecleo "cliente" o "sin nombre" en el campo,
     * y una vez se tecleo un mensaje entero ("Disculpa no hay mas horas
     * disponibles").
     */
    private const NO_SON_NOMBRES = [
        'cliente', 'clienta', 'sin', 'na', 'nn', 'anonimo', 'anonima',
        'disculpa', 'hola', 'no', 'test', 'prueba',
    ];

    private const SOLO_LETRAS = "/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+(?:[ '\\-][A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+)*$/u";

    /**
     * El primer nombre si sirve para saludar, o `null`.
     */
    public static function deSaludo(?string $nombre): ?string
    {
        $limpio = trim((string) $nombre);

        if ($limpio === '' || ! preg_match(self::SOLO_LETRAS, $limpio)) {
            return null;
        }

        $primero = explode(' ', $limpio)[0];

        // Dos letras no distinguen a nadie: "Cc" y "Bb" son fichas reales de
        // Luxury y no son nombres.
        if (mb_strlen($primero) < 3) {
            return null;
        }

        $normalizado = mb_strtolower(strtr($primero, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]));

        return in_array($normalizado, self::NO_SON_NOMBRES, true) ? null : $primero;
    }

    /**
     * Como arranca el mensaje: con su nombre, o sin el.
     *
     * Se devuelve el SALUDO ENTERO y no solo el nombre para que quien escribe
     * la plantilla no tenga que resolver la coma: "Hola {nombre}," con el
     * nombre vacio deja "Hola ,".
     */
    public static function saludo(?string $nombre, string $sinNombre = 'Hola'): string
    {
        $pila = self::deSaludo($nombre);

        return $pila === null ? $sinNombre : "{$sinNombre} {$pila}";
    }
}
