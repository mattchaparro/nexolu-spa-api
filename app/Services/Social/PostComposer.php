<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\User;
use App\Services\Ia\IaCoreClient;

/**
 * Le pide al IA Core el texto de una publicacion.
 *
 * DOS COSAS QUE NO HACE, Y LAS DOS A PROPOSITO:
 *
 * 1. NO NOMBRA A LA CLIENTA. Ni cuando la foto es suya y dio permiso: el
 *    permiso fue para la foto de sus unas, no para que su nombre aparezca en
 *    la vitrina del local. Por eso el encargo que se manda al modelo no
 *    incluye el nombre -- no basta con pedirle que no lo use, porque un dato
 *    que no viaja no se puede filtrar.
 *
 * 2. NO INVENTA PRECIOS NI PROMOCIONES. Es la forma mas facil de que esto
 *    haga dano de verdad: un modelo escribiendo "50% de descuento esta
 *    semana" en el Instagram del negocio es una promesa que alguien va a
 *    llegar a cobrar al mostrador. Los precios que puede decir son los que se
 *    le pasan; si no se le pasa ninguno, no dice ninguno.
 *
 * Si el Core no contesta, esto devuelve null y no es un error del modulo: el
 * negocio escribe su texto a mano y el resto del calendario funciona igual.
 * Un modulo de publicaciones que se cae porque un servicio de IA esta caido
 * no es un modulo de publicaciones.
 */
class PostComposer
{
    /** El tope de Instagram. Cortar aca es mejor que cortarlo alla. */
    private const MAX_CAPTION = 2200;

    /** Tambien de Instagram: mas de 30 y rechaza la publicacion entera. */
    private const MAX_HASHTAGS = 30;

    public function __construct(private readonly IaCoreClient $ia) {}

    /**
     * @return array{caption: string, hashtags: list<string>}|null null si el Core no respondio
     */
    public function write(SocialPost $post, ?User $onBehalfOf = null, ?string $extra = null): ?array
    {
        $text = $this->ia->compose(
            $post->business,
            'contenido',
            $this->brief($post, $extra),
            $onBehalfOf,
        );

        if ($text === null) {
            return null;
        }

        return $this->parse($text);
    }

    /**
     * El encargo.
     *
     * Se arma con lo que el sistema SABE, que es la ventaja entera de que
     * esto viva aca dentro: el servicio, su precio real, el dia que esta
     * libre. Un redactor externo tendria que preguntarlo y quien contesta a
     * las once de la noche es nadie.
     */
    private function brief(SocialPost $post, ?string $extra): string
    {
        $lines = [
            'Escribe el texto de una publicación de Instagram para este negocio.',
            '',
            'Reglas:',
            '- Español de Colombia, cercano y sin solemnidad. Trata de "tú".',
            '- Máximo tres frases cortas, y termina invitando a escribir o a reservar.',
            '- No inventes precios, descuentos ni promociones: solo puedes mencionar lo que aparezca más abajo.',
            '- No menciones nombres de clientas.',
            '- Nada de "¡Hola a todos!" ni de frases de plantilla.',
            '- Al final, en una línea aparte, entre 5 y 10 hashtags en español.',
            '',
            $this->angleBrief($post),
        ];

        if ($post->service !== null) {
            $lines[] = 'El servicio se llama "'.$post->service->name.'".';

            if ($post->service->description) {
                $lines[] = 'Así lo describe el negocio: '.$post->service->description;
            }
        }

        if ($post->location !== null) {
            $lines[] = 'Es en la sede '.$post->location->name
                .($post->location->address ? ' ('.$post->location->address.')' : '').'.';
        }

        /*
         * Lo que escribio la persona al pedir el texto: "menciona que es por
         * el dia de la madre", "no digas nada del precio". Va AL FINAL para
         * que pese mas que lo generado automaticamente, pero despues de las
         * reglas -- que no se negocian.
         */
        if ($extra !== null && trim($extra) !== '') {
            $lines[] = '';
            $lines[] = 'El negocio agrega: '.trim($extra);
        }

        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    private function angleBrief(SocialPost $post): string
    {
        return match ($post->angle) {
            SocialPost::ANGLE_WORK => 'La publicación acompaña la foto de un trabajo que se acaba de hacer en el local. '
                .'Habla del trabajo, no de quien lo lleva puesto.',

            /*
             * El dia y nada mas. Las HORAS libres cambian entre que esto se
             * escribe y que alguien lo publica -- una reserva en el medio y
             * el texto queda mintiendo -- asi que se anuncia el dia y se
             * invita a preguntar, que ademas abre la conversacion donde el
             * agente de WhatsApp ya sabe agendar.
             */
            SocialPost::ANGLE_GAP => 'Quedan horas libres el '.$this->dayName($post)
                .'. Invita a escribir para tomar una, sin decir cuáles horas son.',

            SocialPost::ANGLE_SERVICE => 'La publicación es para recordarle a la gente que este servicio existe. '
                .'No digas que se vende poco.',

            SocialPost::ANGLE_TEAM => 'La publicación presenta a quien atiende en el local.',

            default => 'La publicación es libre: habla del negocio.',
        };
    }

    private function dayName(SocialPost $post): string
    {
        if ($post->subject_date === null) {
            return 'próximos días';
        }

        return $post->subject_date
            ->locale('es')
            ->isoFormat('dddd D [de] MMMM');
    }

    /**
     * Separa el texto de las etiquetas.
     *
     * Se parte por LINEAS y no por una marca acordada con el modelo: un
     * formato pactado ("CAPTION:", "HASHTAGS:") se cumple casi siempre, y
     * "casi" significa que un dia el negocio ve "CAPTION:" publicado en su
     * Instagram. Una linea que solo tiene hashtags es hashtags, sin
     * acuerdos previos que romper.
     *
     * Los hashtags sueltos EN MEDIO del texto se quedan donde estan: ahi son
     * parte de la frase, no etiquetas.
     *
     * @return array{caption: string, hashtags: list<string>}|null
     */
    private function parse(string $text): ?array
    {
        // Algunos modelos devuelven el texto envuelto en un bloque de codigo.
        $text = trim(preg_replace('/^```[a-z]*\R|\R```$/mi', '', trim($text)) ?? '');

        $caption = [];
        $tags = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $clean = trim($line);

            if ($clean !== '' && preg_match('/^(#[\p{L}\p{N}_]+\s*)+$/u', $clean)) {
                preg_match_all('/#[\p{L}\p{N}_]+/u', $clean, $found);
                $tags = array_merge($tags, $found[0]);

                continue;
            }

            $caption[] = $line;
        }

        $caption = trim(implode("\n", $caption));

        if ($caption === '') {
            return null;
        }

        return [
            'caption' => mb_substr($caption, 0, self::MAX_CAPTION),
            'hashtags' => array_slice(array_values(array_unique($tags)), 0, self::MAX_HASHTAGS),
        ];
    }
}
