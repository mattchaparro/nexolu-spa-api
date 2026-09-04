# Publicar en Instagram

## Qué hace

Cuando un negocio conecta su cuenta, una publicación programada **sale sola** a
su hora. Sin cuenta conectada, el módulo sigue funcionando exactamente igual que
antes: llega a «lista para publicar» y alguien copia, pega y la marca.

```
con cuenta:  scheduled --(su hora)--> published
sin cuenta:  scheduled --(su hora)--> ready --(una persona)--> published
```

## La garantía, que no cambió

Durante un tiempo este módulo se detenía en `ready` a propósito, y estaba
escrito que «el sistema no publica». La regla que se estaba defendiendo nunca
fue ésa: era **nadie publica sin que una persona lo haya leído**.

Y esa persona ya pasó. **Programar una publicación *es* aprobarla**, con el
texto y la foto adelante. Lo que el reloj hace después es apretar un botón que
alguien ya decidió apretar.

Lo que sigue sin existir es una publicación que salga sin que nadie la haya
mirado nunca.

## Credenciales: qué va dónde

| Dato | Dónde vive | Quién lo pone |
|---|---|---|
| `INSTAGRAM_APP_ID`, `INSTAGRAM_APP_SECRET` | `.env` — son de la **app de Nexolú**, una para toda la plataforma | El equipo, en el servidor |
| `external_id` de la cuenta + token | fila del negocio, **cifrado** | Cada spa, conectando su cuenta |

**Por negocio y no en `.env`**, por las mismas razones que ya están escritas en
`docs/whatsapp-numero-por-negocio.md`:

- La cuenta es **suya**. La clienta sigue al spa, no a Nexolú.
- El **límite de publicaciones lo pone Meta por cuenta**: compartir una sería que
  un negocio que publica de más deje sin publicar a los demás.
- La **responsabilidad del contenido** es del negocio. Es su vitrina.

Un token en configuración global habría construido esto para *un* spa, y el día
que entre el segundo hay que rehacerlo.

### El token va cifrado, y en dos capas

`encrypted` de Eloquent con la `APP_KEY` que ya existe: en la base queda
ilegible, así que un `select *` de soporte, un backup en un portátil o un
volcado para depurar no exponen la llave para publicar como ese negocio.

Y además en `$hidden`, que protege otra cosa: las respuestas JSON. Un `return
$account` distraído en un controlador no puede filtrarlo. Hay prueba de las dos.

## Conectar una cuenta

Hoy se pegan las credenciales desde **SuperAdmin**:

```
POST /api/v1/superadmin/businesses/{id}/social-account
     { "external_id": "...", "access_token": "..." }
```

Se **verifica contra Meta antes de guardar**. Un token mal copiado guardado en
silencio es una publicación que falla dentro de tres semanas, cuando nadie se
acuerde de esto.

**Esto es un atajo consciente, no la arquitectura.** Lo correcto es el *Embedded
Signup* de Meta: el negocio conecta su propia cuenta desde su panel, en un popup,
y nadie escribe un token nunca. Vive en SuperAdmin y no en el panel del negocio a
propósito — pegar un token de acceso a mano no es una tarea que se le ofrezca a
la dueña de un spa, y un campo así en su pantalla es una invitación a pegar
cualquier cosa que encuentre.

Cuando exista el Embedded Signup, `SocialAccountController` se borra.

## El flujo de Meta, y por qué son tres pasos

Meta no recibe la publicación de una vez. Se crea un **contenedor** y después se
publica:

```
1 imagen:   POST /{ig-id}/media  (image_url + caption)  ->  container
            POST /{ig-id}/media_publish (creation_id)

carrusel:   POST /{ig-id}/media  (image_url, is_carousel_item)  x N
            POST /{ig-id}/media  (media_type=CAROUSEL, children, caption)
            POST /{ig-id}/media_publish (creation_id)
```

El texto va en el contenedor **final**, no en las piezas: ponerlo en cada una lo
repetiría o lo perdería, según el humor de la API.

Entre el contenedor y el publish se espera a que quede en `FINISHED`. Con
imágenes suele ser instantáneo, pero Meta lo documenta como asíncrono, y publicar
un contenedor a medio hacer devuelve un error que parece de otra cosa.

### Meta descarga la imagen; no se la mandamos

`image_url` tiene que ser alcanzable **desde internet, sin firma ni
autenticación**. Dos consecuencias:

- **`APP_URL` tiene que estar bien.** Si no, `Storage::url()` devuelve una ruta
  relativa y Meta no puede descargar nada. Se comprueba antes de llamar, porque
  si no llega como un «media download failure» que manda a buscar el problema en
  la foto en vez de en el `.env`.
- **La compresión importa de verdad.** En un carrusel de diez, es nuestro droplet
  el que sirve esos bytes. Ver `docs/imagenes.md`.

## Lo que se comprueba antes de llamar a Meta

Cuando Meta rechaza, lo que vuelve es un `error_subcode` y una frase en inglés
que no le dice nada a quien maneja un spa — y para entonces ya se creó el
contenedor y ya se consumió cupo del límite diario.

`InstagramLimits` lo convierte en una frase en español que dice **qué hacer**:

| Límite | Valor | De dónde sale |
|---|---|---|
| Imágenes | 10 | Carrusel de Instagram |
| Texto + hashtags | 2200 caracteres | Van juntos: el tope se cuenta sobre la suma |
| Hashtags | 30 | Con 31 rechaza la publicación entera |
| Proporción | entre 4:5 y 1.91:1 | Una mano fotografiada de arriba abajo se pasa de largo |

La proporción se mide **leyendo el archivo guardado**, no lo que se supo al
subirlo: en medio pasó el compresor, que pudo rotarlo por su EXIF — y una foto
rotada tiene la proporción al revés.

## Cuando Meta rechaza

La publicación **no se pierde**: queda en `failed` con el motivo escrito y
visible en el calendario. Una que desaparece porque un token caducó es peor que
una que no salió — nadie sabe que faltó.

**No se reintenta sola.** Casi todos los rechazos de Meta son de los que no se
arreglan esperando (token caducado, proporción mala, cupo diario agotado), y
reintentar cada quince minutos gastaría el cupo en intentos que ya sabemos que
fallan.

`failed` es distinto de volver a la bandeja: una propuesta es algo que nadie miró
todavía, y esto es algo que alguien aprobó y que hay que arreglar. Mezclarlas
escondería el problema entre las ideas.

## Los tokens caducan en silencio

Es el fallo más traicionero de este módulo. Meta da tokens de sesenta días; el
día sesenta y uno las publicaciones dejan de salir **sin error visible**, y nadie
revisa una cuenta que «funcionaba». El negocio se entera semanas después.

Por eso:

- Se guarda `token_expires_at` **preguntándoselo a Meta** (`debug_token`), no
  asumiendo sesenta días: hay tokens que no caducan nunca, y ponerles una fecha
  inventada haría que el panel avise de un vencimiento que no existe.
- `redes:renovar-tokens` corre **a diario** y renueva con **siete días** de
  anticipación. Diario y no mensual: así una corrida perdida tiene seis
  reintentos. Mensual daría un solo intento.
- Una renovación fallida **no borra el token viejo**: todavía sirve hasta que
  venza, y quedarse sin nada por un fallo de red sería cambiar un problema de la
  semana que viene por uno de ahora mismo.
- El panel avisa desde diez días antes.

```bash
php artisan redes:renovar-tokens
```

Requiere el cron de Laravel, igual que todo lo demás.

## Apagar sin desconectar

`is_active` en false deja de publicar automáticamente y las publicaciones vuelven
a quedar en «lista para publicar». Un negocio que quiere parar un mes no debería
tener que volver a pasar por Meta para volver.

## Lo que falta

- **El Embedded Signup.** Mientras no exista, el token se pega a mano desde
  SuperAdmin.
- **El límite diario no se consulta.** Instagram permite **25 publicaciones por
  cuenta cada 24 horas** y la API lo expone en
  `GET /{ig-user-id}/content_publishing_limit`. Hoy nos enteramos cuando rechaza.
- **Sólo imágenes.** Los reels y los vídeos usan otro tipo de contenedor y son
  de verdad asíncronos.
- **No se lee nada de vuelta.** Cuántas personas vieron o escribieron después de
  una publicación es el dato que diría si esto sirve, y necesita otros permisos.
