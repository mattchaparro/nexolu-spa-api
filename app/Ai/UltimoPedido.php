<?php

namespace App\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Lo último que la clienta pidió, para no hacerla repetirlo.
 *
 * Las clientas simuladas destaparon el mismo derrumbe ocho veces de
 * ocho: en cuanto la conversación pasaba de dos turnos, el modelo perdía
 * algo que ya le habían dicho. Tocaba "Tradicional" después de pedir
 * "mañana en la mañana" y el modelo llamaba a la agenda con «hoy»;
 * tocaba "6 pm" y el modelo llamaba sin servicio, la herramienta
 * rechazaba la llamada y la clienta leía "no pude consultar la agenda".
 * Nadie llegó a agendar.
 *
 * El modelo no es un buen sitio para guardar estado. Esto sí lo es: cada
 * vez que una herramienta entiende un pedido -- qué servicios, qué día,
 * qué franja, para cuántas -- lo deja acá, y la siguiente llamada
 * completa lo que el modelo olvidó. Y cuando lo que llega es un TOQUE
 * sobre algo que se ofreció, lo guardado manda por encima de lo que el
 * modelo diga: la clienta ya dijo el día; el "hoy" se lo inventó él.
 *
 * Vive el resto del día --hasta ocho horas--, en caché y no en la
 * conversación, porque es de una gestión: quien la deja a medias la retoma
 * cuando puede, y si vuelve mañana empieza de cero.
 */
final class UltimoPedido
{
    /*
     * Ocho horas, y nunca más allá de la medianoche (ver `clave`).
     *
     * Eran treinta minutos, y la clienta que se distraía en el trabajo
     * volvía a una conversación que ya no sabía de qué le hablaba: tocaba
     * una hora de la lista y el bot la saludaba desde cero. Quien deja una
     * cita a medias la retoma cuando puede, no a la media hora.
     *
     * Las horas que se le ofrecieron pueden haberse ocupado mientras tanto,
     * pero eso ya está cubierto: al confirmar, la reserva vuelve a mirar la
     * agenda y si la hora se fue lo dice y ofrece otras.
     */
    private const TTL_SEGUNDOS = 28800;

    /** Lo que se puede completar desde lo guardado. */
    private const CAMPOS = ['fecha', 'franja', 'juntas', 'empleado', 'sede', 'para_quien', 'nombres'];

    /**
     * @param  array<string, mixed>  $pedido  servicios (nombres reales), fecha (como la dijo),
     *                                        franja, juntas, empleado, sede, opciones (títulos ofrecidos)
     */
    public static function guardar(string $phone, array $pedido): void
    {
        $limpio = array_filter($pedido, fn ($v) => $v !== null && $v !== '' && $v !== []);

        Cache::put(self::clave($phone), $limpio, self::TTL_SEGUNDOS);
    }

    /** @return array<string, mixed> */
    public static function ver(string $phone): array
    {
        return Cache::get(self::clave($phone), []);
    }

    public static function olvidar(string $phone): void
    {
        Cache::forget(self::clave($phone));
    }

    /**
     * Los argumentos de una llamada, completados con lo que ya se sabía.
     *
     * Dos reglas, y el orden importa:
     *
     * 1. Si `servicio` es uno de los que se le OFRECIERON (lo tocó), el
     *    día, la franja y el resto del pedido guardado pisan lo que traiga
     *    la llamada. Es el turno donde el modelo peor recuerda, y lo que
     *    la clienta dijo antes vale más que lo que él supone ahora.
     * 2. Lo que no venga se rellena con lo guardado -- incluido el
     *    servicio, para que "6 pm" después de ver las horas no llegue sin
     *    saber de qué.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function completar(string $phone, array $arguments): array
    {
        $pedido = self::ver($phone);

        if ($pedido === []) {
            return $arguments;
        }

        $eleccion = isset($arguments['servicio'])
            ? self::destruncar((string) $arguments['servicio'], $pedido['opciones'] ?? [])
            : null;
        $tocado = $eleccion !== null;

        if ($tocado) {
            // El nombre COMPLETO del catalogo, no el recorte del boton.
            $arguments['servicio'] = $eleccion;
        }

        /*
         * El servicio que la clienta ESCRIBIO, cuando es una version mas
         * especifica del que eligio el modelo (ver ServicioEnTexto). Julian
         * dijo «semipermanente» y enseguida «pero de hombre»; el modelo
         * llamo con «semipermanente» a secas, y la cita iba a salir por el
         * precio del de mujer.
         */
        $vigentes = ($pedido['servicios_dichos_hasta'] ?? 0) >= now()->getTimestamp()
            ? ($pedido['servicios_dichos'] ?? [])
            : [];

        if (! $tocado && ! empty($arguments['servicio']) && $vigentes !== []) {
            $precisado = ServicioEnTexto::masEspecifico((string) $arguments['servicio'], $vigentes);

            if ($precisado !== null) {
                $arguments['servicio'] = $precisado;
            }
        }

        /*
         * Lo que la clienta ESCRIBIO en su ultimo mensaje ("mañana", "en
         * la tarde") manda sobre lo que el modelo haya puesto, mientras
         * dure la ventana (ver DateInText::remember). Valentina dijo
         * "para mañana despues de las 5" y el modelo llamo con fecha=hoy:
         * su palabra vale mas que la interpretacion.
         */
        $textoManda = ($pedido['texto_manda_hasta'] ?? 0) >= now()->getTimestamp()
            ? ($pedido['texto_manda'] ?? [])
            : [];

        foreach (self::CAMPOS as $campo) {
            $traeAlgo = isset($arguments[$campo]) && $arguments[$campo] !== '';

            if (isset($pedido[$campo]) && ($tocado || in_array($campo, $textoManda, true) || ! $traeAlgo)) {
                $arguments[$campo] = $pedido[$campo];
            }
        }

        $sinServicio = empty($arguments['servicio']) && empty($arguments['servicios']);

        if ($sinServicio && ! empty($pedido['servicios'])) {
            $arguments['servicios'] = array_values($pedido['servicios']);
            unset($arguments['servicio']);
        }

        return $arguments;
    }

    /**
     * ¿Este texto es una de las opciones ofrecidas? Devuelve cuál, completa.
     *
     * No basta comparar igual por igual: WhatsApp corta los títulos de las
     * listas a 24 caracteres, así que lo que vuelve al tocar «Recubrimiento
     * Rubber sin esmaltado» es «Recubrimiento Rubber si». Con el catálogo
     * real (nombres largos) el toque no calzaba con nada, caía al modelo y
     * el modelo volvía a preguntar lo que la clienta acababa de tocar.
     *
     * @param  list<string>  $opciones
     */
    public static function destruncar(string $texto, array $opciones): ?string
    {
        // Fuera puntos suspensivos (los pone quien recorta) y espacios.
        $t = rtrim(self::plano(preg_replace('/(\.{3}|…)\s*$/u', '', $texto) ?? $texto));

        if ($t === '') {
            return null;
        }

        foreach ($opciones as $opcion) {
            $o = self::plano((string) $opcion);

            // Igual, o el recorte de 24 del título completo. El mínimo de
            // 15 evita que un toque corto "elija" por accidente.
            if ($o === $t || (mb_strlen($t) >= 15 && str_starts_with($o, $t))) {
                return (string) $opcion;
            }
        }

        return null;
    }

    /**
     * La clave de un mapa "título tocado → valor", aguantando el recorte.
     *
     * Mismo problema que `destruncar`, pero para las listas que guardan un
     * valor por fila (las citas, los días): Alejandro tocó la fila «Sáb. 26
     * sep. · 3:30 pm» y volvió «Sáb. 26 sep. · 3:30» -- sin el "pm". El bot
     * buscó exacto, no la encontró, y la cancelación terminó en un "no
     * entendí la fecha".
     *
     * @param  array<string, mixed>  $mapa
     */
    public static function claveDe(array $mapa, string $texto): ?string
    {
        $t = rtrim(self::plano(preg_replace('/(\.{3}|…)\s*$/u', '', $texto) ?? $texto));

        if ($t === '' || $mapa === []) {
            return null;
        }

        if (array_key_exists($t, $mapa)) {
            return $t;
        }

        foreach (array_keys($mapa) as $clave) {
            // El mínimo de 12 evita que un toque corto calce por accidente.
            if (mb_strlen($t) >= 12 && str_starts_with((string) $clave, $t)) {
                return (string) $clave;
            }
        }

        return null;
    }

    private static function plano(string $texto): string
    {
        return trim(mb_strtolower(strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'])));
    }

    /**
     * Con el DÍA en la llave: un pedido de ayer no se ve hoy.
     *
     * El pedido guarda la fecha como la dijo la clienta -- «mañana», «hoy»
     * -- y se resuelve al usarla. Con treinta minutos de vida eso casi nunca
     * cruzaba la medianoche; con ocho horas sí: quien pidiera «mañana» a
     * las once de la noche y volviera a la una recibiría horas del día
     * equivocado. Cambiar de día es cambiar de llave, y lo de ayer
     * simplemente no está.
     *
     * La zona es la del NEGOCIO por defecto, no la de la aplicación: la
     * aplicación corre en UTC, y con ella la medianoche caía a las siete de
     * la noche en Colombia -- el pedido se habría borrado todos los días en
     * pleno horario del salón. No se usa la zona de cada negocio porque aquí
     * no se sabe de cuál es el pedido; hoy todos son de Colombia, y si llega
     * uno de otra zona esto hay que mirarlo.
     */
    private static function clave(string $phone): string
    {
        $dia = now(config('spa.defaults.timezone', 'America/Bogota'))->toDateString();

        return 'ia:ultimo-pedido:'.ltrim($phone, '+').':'.$dia;
    }
}
