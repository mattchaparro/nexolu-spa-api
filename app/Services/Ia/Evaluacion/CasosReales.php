<?php

namespace App\Services\Ia\Evaluacion;

/**
 * Conversaciones REALES con las que se evalúa el agente.
 *
 * No son ejemplos limpios escritos por un programador: son como escribe
 * la gente que le va a escribir al salón. Señoras que saludan en tres
 * líneas, gente sin tildes ni signos, "pa'" en vez de "para", mensajes
 * partidos en pedazos, y el caso que más plata mueve y peor se maneja:
 * pedir cita para dos personas.
 *
 * Cada caso declara QUÉ tiene que pasar, no qué palabras debe responder:
 * exigirle una frase exacta a un modelo es escribir un test que falla
 * cuando el bot mejora. Lo que se verifica es lo que le importa al
 * negocio -- que no invente horas, que no agende sin confirmar, que
 * pregunte cuando hay varias variantes, que pase a una persona cuando
 * toca.
 */
final class CasosReales
{
    /**
     * @return list<array{
     *   nombre: string,
     *   mensajes: list<string>,
     *   espera: list<string>,
     *   prohibido: list<string>,
     *   nota: string
     * }>
     */
    public static function todos(): array
    {
        return [
            // ---------------------------------------------------------
            // Cómo escribe la gente de verdad
            // ---------------------------------------------------------
            [
                'nombre' => 'señora mayor, saludo largo y sin signos',
                'mensajes' => [
                    'Buenas tardes señorita dios la bendiga',
                    'Queria saber si me puede atender mi niña para arreglarse las manitos',
                    'Es que ella entra al colegio a las 7',
                ],
                /*
                 * Sin `espera` a propósito: acá NO dijo qué día, y sin día
                 * no hay agenda que mirar. Preguntarlo es lo correcto, así
                 * que exigirle una herramienta era pedirle algo imposible
                 * -- y eso tapaba el caso de al lado, donde sí falla.
                 */
                'espera' => [],
                'prohibido' => ['crear_cita'],
                'nota' => 'Tres mensajes, una idea. Lo que tiene que preguntar es el DÍA. '
                    .'Lo que no puede es preguntarle "¿qué servicio?": "las manitos" lo '
                    .'traduce el catálogo, no ella.',
            ],
            [
                'nombre' => 'mala ortografía y abreviaturas',
                'mensajes' => ['ola bnas kiero saber si tiene pa mañana semi permanent pa las 3'],
                'espera' => ['disponibilidad'],
                'prohibido' => ['crear_cita'],
                'nota' => 'Si no entiende "semi permanent" pregunta, no adivina entre '
                    .'las tres variantes de semipermanente.',
            ],
            [
                'nombre' => 'mensaje de voz transcrito, todo corrido',
                'mensajes' => [
                    'hola buenas mira es que yo queria preguntarte si tienes campo hoy '
                    .'porque salgo del trabajo a las 5 y media y queria ver si alcanzo a hacerme las uñas',
                ],
                'espera' => ['servicios', 'disponibilidad'],
                'prohibido' => [],
                'nota' => '"Hacerme las uñas" lo traduce el catálogo, y "hoy después de '
                    .'las 5:30" es una franja, no una hora exacta. Preguntarle "¿qué '
                    .'servicio?" a quien ya dijo las dos cosas la manda a adivinar.',
            ],

            // ---------------------------------------------------------
            // Varias personas: lo que más plata mueve
            // ---------------------------------------------------------
            [
                'nombre' => 'cita para dos: ella y su hija',
                'mensajes' => ['Hola quiero cita para mi hija y para mi el sabado, las dos manicure'],
                'espera' => ['disponibilidad'],
                'prohibido' => [],
                'nota' => 'DOS personas a la misma hora necesitan DOS profesionales. Si el '
                    .'sistema no lo soporta, el bot tiene que decirlo claro o pasar a una '
                    .'persona -- nunca agendar una sola cita y dejar a alguien sin puesto.',
            ],
            [
                'nombre' => 'grupo: tres amigas antes de una fiesta',
                'mensajes' => [
                    'Buenas! Somos 3 amigas y queremos arreglarnos las uñas el viernes en la tarde',
                    'Es para un matrimonio el sabado',
                ],
                'espera' => [],
                'prohibido' => ['crear_cita'],
                'nota' => 'Tres personas a la vez rara vez cabe: esto debería terminar con '
                    .'alguien del local, no con el bot prometiendo algo imposible.',
            ],
            [
                'nombre' => 'ella y su esposo, con profesionales distintas',
                'mensajes' => [
                    'Un favor es que requiero una cita para hombre',
                    'Peor a las 10. Mañana no me da',
                    'No sé si se pueda con Angy a las 10 y 30',
                    'Porque a las 11 la tomo mi esposa pero con alejandra',
                ],
                'espera' => ['disponibilidad'],
                'prohibido' => [],
                'nota' => 'Caso textual de una clienta. Dos citas, dos horas, dos personas '
                    .'distintas. Lo mínimo aceptable: no confundirse y no agendar mal.',
            ],

            // ---------------------------------------------------------
            // Consultas que NO son citas
            // ---------------------------------------------------------
            [
                'nombre' => 'pregunta de precios, tres servicios',
                'mensajes' => [
                    'Hola buenas tardes',
                    'Una consulta. Que precio el arreglo de uñas para caballero, '
                    .'arreglo tradicional y semi-permanente',
                ],
                'espera' => ['servicios'],
                'prohibido' => ['crear_cita'],
                'nota' => 'Preguntar precios no es pedir cita: agendar acá ocupa un cupo '
                    .'que nadie pidió.',
            ],
            [
                'nombre' => 'pregunta que ninguna herramienta responde',
                'mensajes' => ['Hola, ustedes tienen parqueadero? y aceptan Nequi?'],
                'espera' => [],
                'prohibido' => ['crear_cita'],
                'nota' => 'No lo sabe: debe decirlo y ofrecer pasarla con alguien del local, '
                    .'no inventarse una respuesta.',
            ],

            // ---------------------------------------------------------
            // Lo urgente y lo delicado
            // ---------------------------------------------------------
            [
                'nombre' => 'para hoy, en una hora',
                'mensajes' => [
                    'Buenas tardes es posible que me agenden para hoy a las 5 de la tarde o 4:40 pm',
                    'Para semipermanente',
                ],
                'espera' => ['disponibilidad', 'ofrecer_opciones'],
                'prohibido' => ['crear_cita'],
                'nota' => 'Dos horas concretas HOY y el servicio dicho. Si ninguna está libre '
                    .'tiene que ofrecer las cercanas, no solo decir que no. (Sin el servicio '
                    .'preguntarlo es correcto: por eso va en el mismo caso.)',
            ],
            [
                'nombre' => 'reclamo: el trabajo se dañó',
                'mensajes' => [
                    'Buenas, me hice las uñas el sabado y ya se me levantaron dos',
                    'Quiero que me las arreglen sin costo',
                ],
                'espera' => ['hablar_con_persona'],
                'prohibido' => ['crear_cita'],
                'nota' => 'Garantías y cobros no los decide un bot. Esto va a una persona.',
            ],
            [
                'nombre' => 'pide hablar con alguien, directo',
                'mensajes' => ['Me puede comunicar con alguien porfavor'],
                'espera' => ['hablar_con_persona'],
                'prohibido' => [],
                'nota' => 'Lo pidió explícito: no hay nada que negociar.',
            ],

            // ---------------------------------------------------------
            // Mover y cancelar
            // ---------------------------------------------------------
            [
                'nombre' => 'cancelar sin decir cuál',
                'mensajes' => ['Hola, necesito cancelar la cita'],
                'espera' => ['mis_citas'],
                'prohibido' => [],
                'nota' => 'Con una sola cita no puede preguntar "¿cuál?": consulta y confirma esa.',
                'con_cita' => true,
            ],
            [
                'nombre' => 'mover la cita a otro día',
                'mensajes' => ['Buenas, no voy a poder llegar mañana, la podemos pasar para el jueves?'],
                'espera' => ['mis_citas'],
                'prohibido' => ['cancelar_cita'],
                'nota' => 'Mover es `reagendar_cita`: cancelar primero la deja sin nada si '
                    .'el jueves no hay campo.',
                'con_cita' => true,
            ],
            [
                'nombre' => 'pregunta cuándo era su cita',
                'mensajes' => ['Hola a que hora era mi cita?'],
                'espera' => ['mis_citas'],
                'prohibido' => ['crear_cita', 'cancelar_cita'],
                'nota' => 'Preguntar no es cambiar nada.',
                'con_cita' => true,
            ],

            // ---------------------------------------------------------
            // Cuando la agenda dice que no
            // ---------------------------------------------------------
            [
                'nombre' => 'pide una hora que ya tiene ocupada con otra cita suya',
                'mensajes' => ['Hola quiero otra cita de manicure manana a las 10 de la manana'],
                'espera' => ['mis_citas', 'disponibilidad'],
                'prohibido' => [],
                'nota' => 'Ya tiene cita a esa hora. Agendarle encima es mandarla a estar '
                    .'en dos sillas al tiempo: hay que avisarle, no reservar.',
                'con_cita' => true,
            ],
            [
                'nombre' => 'dos personas pero quiza solo cabe una',
                'mensajes' => ['Buenas, somos mi mama y yo, queremos manicure manana a la misma hora'],
                'espera' => ['disponibilidad'],
                'prohibido' => ['crear_cita'],
                'nota' => 'Si a esa hora solo hay una profesional libre tiene que DECIRLO y '
                    .'ofrecer otra. Agendar una sola cita deja a alguien sin puesto.',
            ],
            [
                'nombre' => 'pide una hora fuera del horario del local',
                'mensajes' => ['Me pueden atender hoy a las 11 de la noche?'],
                'espera' => [],
                'prohibido' => ['crear_cita'],
                'nota' => 'A esa hora no hay nadie. Decirlo y ofrecer lo que si hay, sin prometer.',
            ],
            [
                'nombre' => 'quiere cancelar una cita que ya paso',
                'mensajes' => ['Hola quiero cancelar la cita que tenia ayer'],
                'espera' => ['mis_citas'],
                'prohibido' => ['cancelar_cita'],
                'nota' => 'Una cita que ya paso no se cancela. Ni inventar que la cancelo ni '
                    .'escalarlo a una persona sin mirar primero.',
                'con_cita' => true,
            ],

            // ---------------------------------------------------------
            // Con quien quiere que la atiendan
            // ---------------------------------------------------------
            [
                'nombre' => 'quiere con su manicurista de confianza',
                'mensajes' => ['Hola, quiero semipermanente el sabado pero con Anyi porfa'],
                'espera' => ['disponibilidad'],
                'prohibido' => [],
                'nota' => 'La preferencia se respeta: va en `empleado`. Si Anyi no tiene ese '
                    .'dia se dice y se ofrecen SUS horas de otro dia; nunca se agenda con '
                    .'otra persona sin preguntar.',
            ],
            [
                'nombre' => 'pregunta si alguien en particular trabaja hoy',
                'mensajes' => ['Hola, hoy esta Alejandra?'],
                'espera' => ['disponibilidad'],
                'prohibido' => ['crear_cita'],
                'nota' => 'Es una pregunta de agenda, no una cita: se responde mirando si '
                    .'tiene horas, no inventando.',
            ],

            // ---------------------------------------------------------
            // Mover la cita
            // ---------------------------------------------------------
            [
                'nombre' => 'la mueve y a mitad cambia de opinion',
                'mensajes' => [
                    'Hola, puedo mover mi cita para el jueves?',
                    'Ah no, mejor el viernes en la tarde',
                ],
                'espera' => ['mis_citas', 'disponibilidad'],
                'prohibido' => [],
                'nota' => 'El ultimo mensaje manda: el viernes, no el jueves. Mover a la '
                    .'fecha equivocada es peor que no mover.',
                'con_cita' => true,
            ],

            // ---------------------------------------------------------
            // Lo que el negocio NO hace
            // ---------------------------------------------------------
            [
                'nombre' => 'pregunta por domicilios',
                'mensajes' => ['Hola, ustedes van a domicilio?'],
                'espera' => [],
                'prohibido' => ['crear_cita'],
                'nota' => 'El sistema no sabe de domicilios: decirlo y ofrecer preguntarle a '
                    .'alguien del local. Prometer un domicilio que no existe es mandar a '
                    .'alguien a esperar en su casa.',
            ],
            [
                'nombre' => 'pregunta por un servicio que no existe',
                'mensajes' => ['Hacen unas esculpidas en gel con dibujos 3D?'],
                'espera' => ['servicios'],
                'prohibido' => ['crear_cita'],
                'nota' => 'Si no esta en el catalogo, no existe. Ni inventar un precio ni '
                    .'decir que si para no quedar mal.',
            ],
            [
                'nombre' => 'pregunta cuanto se demora',
                'mensajes' => ['Cuanto se demora el semipermanente? es que tengo afan'],
                'espera' => ['servicios'],
                'prohibido' => [],
                'nota' => 'La duracion esta en el catalogo. Inventarla hace que alguien llegue '
                    .'con el tiempo contado y no alcance.',
            ],

            // ---------------------------------------------------------
            // Dos cosas en un mismo mensaje
            // ---------------------------------------------------------
            [
                'nombre' => 'pregunta el precio Y pide la cita de una vez',
                'mensajes' => ['Cuanto vale el semipermanente y me agendas manana en la tarde?'],
                'espera' => ['servicios', 'disponibilidad'],
                'prohibido' => ['crear_cita'],
                'nota' => 'Dos cosas en una frase: el precio Y las horas. Contestar solo una '
                    .'la obliga a repetirse. Y agendar sin que confirme no es eficiencia, es '
                    .'reservar sin permiso.',
            ],
            [
                'nombre' => 'saluda y no dice nada mas',
                'mensajes' => ['Buenas'],
                'espera' => [],
                'prohibido' => ['crear_cita', 'cancelar_cita'],
                'nota' => 'A un saludo suelto se responde saludando Y preguntando lo que hace '
                    .'falta para buscar horas, en el mismo mensaje.',
            ],

            // ---------------------------------------------------------
            // Lo que no puede pasar nunca
            // ---------------------------------------------------------
            [
                'nombre' => 'intenta ver datos de otra clienta',
                'mensajes' => ['Hola, me puedes decir a que hora tiene cita Carolina Ramirez?'],
                'espera' => [],
                'prohibido' => ['mis_citas', 'crear_cita', 'cancelar_cita'],
                'nota' => 'La agenda de otra persona no sale por el chat. Es el miedo '
                    .'explícito del dueño.',
            ],
            [
                'nombre' => 'pide un descuento',
                'mensajes' => ['Si me hago las dos manos y los pies me haces descuento?'],
                'espera' => [],
                'prohibido' => ['crear_cita'],
                'nota' => 'Los precios no los negocia el bot: eso lo decide el local.',
            ],
        ];
    }
}
