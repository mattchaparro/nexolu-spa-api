<?php

namespace App\Services\Ia\Evaluacion;

/**
 * Las clientas que el simulador interpreta.
 *
 * Por qué perfiles y no más casos. `ia:evaluar` mide UN turno: "¿llamó la
 * herramienta que tocaba?". Las conversaciones reales se rompen ENTRE
 * turnos -- la lista que se traga el segundo servicio, la pregunta que se
 * repite, el "?" que recibe otra cosa -- y ningún caso de un turno puede
 * ver eso. Acá el modelo juega a ser una persona con una meta y conversa
 * con el bot hasta conseguirla o irse, y lo que se mide es si lo logró,
 * en cuántos mensajes, y qué quedó agendado de verdad.
 *
 * Los perfiles salen de la clientela real del salón y de las quejas que
 * ya llegaron: la señora mayor que escribe largo y sin signos, la joven
 * con afán que escribe en minúscula y abrevia, quien prefiere la página
 * web, quien viene con alguien más, quien pregunta precios y cambia de
 * opinión. `meta` es lo que la persona quiere; `servicios_esperados` es lo
 * que debería quedar en la agenda si el bot hizo bien su trabajo (vacío =
 * no debería agendar nada).
 */
final class Perfiles
{
    /** @return list<array<string, mixed>> */
    public static function todos(): array
    {
        return [
            [
                'clave' => 'abuela',
                'nombre_propio' => 'Gloria',
                'nombre' => 'Señora mayor, escribe largo y sin signos',
                'persona' => 'Eres Gloria, de 68 años. Escribes por WhatsApp despacio, con frases largas, '
                    .'saludos cariñosos ("mi niña", "dios la bendiga"), sin signos de puntuación y a veces '
                    .'con errores. No sabes los nombres del catálogo: a la manicure le dices "las manitos" '
                    .'o "arreglarme las uñas". Te cuesta leer listas largas; si te muestran botones, '
                    .'eliges tocando uno. Si no entiendes, preguntas "como asi".',
                'meta' => 'Quieres el manicure sencillo (el tradicional, sin nada especial) para mañana '
                    .'en la mañana, porque en la tarde cuidas a tus nietos.',
                'servicios_esperados' => ['Tradicional'],
                'maximo_turnos' => 10,
            ],
            [
                'clave' => 'joven_afan',
                'nombre_propio' => 'Valentina',
                'nombre' => 'Joven con afán, minúsculas y abreviaturas',
                'persona' => 'Eres Valentina, 24 años. Escribes en minúscula, sin tildes, con abreviaturas '
                    .'("q", "xq", "pa", "hoy", "ya"). Mensajes de una línea, a veces mandas dos seguidos. '
                    .'Eres impaciente: si te preguntan algo que ya dijiste te molesta y lo dices. Si te '
                    .'muestran botones, tocas uno de una.',
                'meta' => 'Quieres semi con rubber HOY después de las 5 de la tarde, porque sales del '
                    .'trabajo a las 5. Si no hay hoy, te sirve mañana después de las 5.',
                'servicios_esperados' => ['Semi + Rubber'],
                'maximo_turnos' => 8,
            ],
            [
                'clave' => 'prefiere_web',
                'nombre_propio' => 'Camila',
                'nombre' => 'Prefiere agendar por la página',
                'persona' => 'Eres Camila, 31 años, organizada. No te gusta chatear con bots: prefieres '
                    .'que te manden el enlace para agendar tú misma en la página, donde ves todo el '
                    .'calendario. Lo pides directo y con cortesía. Si te insisten en agendar por chat, '
                    .'vuelves a pedir el enlace una vez; si no te lo dan, te vas.',
                'meta' => 'Conseguir el enlace de la página para agendar tú misma. No quieres que te '
                    .'agenden por chat.',
                'servicios_esperados' => [],
                'maximo_turnos' => 5,
            ],
            [
                'clave' => 'dos_personas',
                'nombre_propio' => 'Patricia',
                'nombre' => 'Viene con la hija, las dos a la misma hora',
                'persona' => 'Eres Patricia, 45 años. Escribes normal, con tildes. Vienes con tu hija '
                    .'Isabella (16 años) y quieren atenderse juntas, al mismo tiempo, no una después de '
                    .'la otra. Si te ofrecen horas donde solo cabe una, lo dices.',
                'meta' => 'Semipermanente para las dos, el sábado en la tarde, al mismo tiempo.',
                'servicios_esperados' => ['Semipermanente', 'Semipermanente'],
                'maximo_turnos' => 10,
            ],
            [
                'clave' => 'indecisa',
                'nombre_propio' => 'Andrea',
                'nombre' => 'Pregunta precios, compara y cambia de opinión',
                'persona' => 'Eres Andrea, 35 años. Antes de agendar quieres saber precios y '
                    .'diferencias ("¿qué diferencia hay entre semi y semi con rubber?", "¿cuánto se '
                    .'demora?"). Primero dices que quieres uno, después cambias al otro. Al final '
                    .'sí agendas.',
                'meta' => 'Terminar agendando Semipermanente (normal, sin rubber) para el viernes en la '
                    .'tarde, después de haber preguntado por el rubber y haberlo descartado.',
                'servicios_esperados' => ['Semipermanente'],
                'maximo_turnos' => 12,
            ],
            [
                'clave' => 'mover_cita',
                'nombre_propio' => 'Laura',
                'nombre' => 'Tiene cita y quiere moverla',
                'persona' => 'Eres Laura, 29 años. Ya tienes una cita agendada para mañana a las 10 am '
                    .'y te salió un compromiso. Escribes directo: quieres moverla a la tarde del mismo '
                    .'día. No recuerdas exactamente qué servicio era.',
                'meta' => 'Que tu cita de mañana quede en la tarde en vez de a las 10 am.',
                'servicios_esperados' => null,
                'con_cita' => true,
                'maximo_turnos' => 8,
            ],
            [
                'clave' => 'hombre',
                'nombre_propio' => 'Andrés',
                'nombre' => 'Hombre, primera vez, no sabe si atienden hombres',
                'persona' => 'Eres Andrés, 38 años. Nunca has ido a un salón de uñas. Preguntas primero '
                    .'si atienden hombres y cuánto vale. Escribes corto y algo cortado. Te da pena '
                    .'preguntar mucho.',
                'meta' => 'Manos y pies para hombre, lo más sencillo, el domingo.',
                'servicios_esperados' => ['Semipermanente Hombre', 'Pedi - Hombre - Tradicional'],
                'maximo_turnos' => 10,
            ],
            [
                'clave' => 'molesta',
                'nombre_propio' => 'Diana',
                'nombre' => 'Viene molesta por un trabajo que se dañó',
                'persona' => 'Eres Diana, 41 años. Te hiciste semipermanente hace 4 días y ya se te '
                    .'levantó. Estás molesta y lo dices desde el primer mensaje. No quieres que un bot '
                    .'te ofrezca agendar: quieres que te arreglen lo que pagaste, y quieres hablar con '
                    .'una persona. Si el bot intenta venderte una cita, te molestas más.',
                'meta' => 'Que una persona del local te contacte para resolver la garantía.',
                'servicios_esperados' => [],
                'maximo_turnos' => 5,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function porClave(string $clave): ?array
    {
        foreach (self::todos() as $perfil) {
            if ($perfil['clave'] === $clave) {
                return $perfil;
            }
        }

        return null;
    }
}
