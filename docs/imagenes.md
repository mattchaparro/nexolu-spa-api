# Las imágenes del producto

## Por dónde pasan

**Todas por `ImageStorage::store()`**, sin excepciones: fotos de servicios, del
equipo, del trabajo hecho, comprobantes de pago e imágenes de publicaciones. Que
sea un solo punto es lo que permite que la compresión y el prefijo por negocio
se apliquen sin que cada controlador se acuerde.

```
subida  ->  ImageCompressor  ->  disco  ->  URL pública
```

## Dónde se guardan

Hoy en el **disco local del droplet** (`storage/app/public`), servidas por nginx
en `https://agenda-backend.nexolu.co/storage/...`.

`config/filesystems.php` tiene un disco `spaces` listo, pero **no está
instalado**: `league/flysystem-aws-s3-v3` y `aws/aws-sdk-php` no son
dependencias de este proyecto. Poner `SPACES_KEY` en el `.env` hoy hace que la
primera subida reviente con `Class "Aws\S3\S3Client" not found`. Es una decisión
pendiente, no un olvido — ver «Lo que falta».

### `storage:link` no es opcional

Sin el enlace `public/storage`, **todas** las imágenes responden 404: la subida
funciona, el archivo queda guardado, y sólo falla la URL. Un fallo silencioso
que nadie nota hasta que abre una ficha.

Lo corre `docker/entrypoint.sh` en cada arranque (`--force`, idempotente) y
`composer setup` en local. Va en el arranque y no en el build porque `storage/`
es un volumen montado.

## Qué se le hace a cada imagen

| Paso | Por qué |
|---|---|
| Se endereza según el EXIF | El celular no rota los píxeles: escribe «está girada» en el EXIF. GD no lo aplica, así que sin esto una foto vertical se guarda acostada — **y para siempre**, porque al recodificar el EXIF se pierde |
| Se achica al borde largo | 1600 px normal, **2400 px** para documentos |
| Se recomprime | JPEG a calidad 82 |
| Se van los metadatos | Efecto de recodificar, y es de los más importantes |

**Medido** sobre una foto de celular de 4032x3024:

```
antes:   4032x3024   3.133 KB
después: 1600x1200     310 KB      -90%
un carrusel de 10:  30,6 MB  ->  3,0 MB
```

Cuesta ~730 ms por imagen, en el momento de subirla. Es aceptable: subir una
foto es una acción puntual, y el droplet tiene un solo core que no conviene
ocupar sirviendo 3 MB una y otra vez.

### Por qué 1600, y por qué 2400 para comprobantes

Nadie ve 4000 píxeles: la ficha muestra la foto a 144, el carrusel a 96, e
Instagram reescala a 1080 de todas formas. 1600 deja margen para el zoom que sí
hace la profesional al reproducir un trabajo.

Un **comprobante de transferencia** es otra cosa: es un pantallazo de la app del
banco y lo único que importa es el texto. Un celular actual entrega 1170x2532;
bajarlo a 1600 de alto deja el ancho en 739, y ahí los números quedan al límite
de legibles. Un comprobante ilegible no es evidencia de nada, y el cierre del
día vuelve a cuadrar contra lo que alguien *dijo* que entró.

### Los metadatos no son un detalle

Una foto de celular trae las **coordenadas GPS** de dónde se tomó. Publicar la
foto de las uñas de una clienta con la ubicación exacta incrustada es un dato
que ella nunca dio. Al recodificar, GD no copia el EXIF: desaparece solo.

## Tres reglas del compresor

### 1. Nunca hace fallar una subida

Si GD no está, si la imagen es rara, si algo revienta — se guarda el original y
ya. Perder la foto del trabajo de alguien porque una librería no estaba
instalada sería un intercambio pésimo. `compress()` devolviendo `null` significa
«guarda el original», no «falló».

### 2. No convierte de formato

Un PNG sigue siendo PNG. Aplanar a JPEG le pondría fondo negro a cualquier logo
con transparencia — y eso se descubre en la página pública. Además Instagram
sólo acepta JPEG y PNG: pasar todo a WebP rompería la publicación.

### 3. Si el resultado pesa más, se descarta

Pasa de verdad con imágenes ya optimizadas o PNG planos de pocos colores.
Quedarse con el resultado «porque pasamos por el compresor» sería empeorar el
archivo por seguir un proceso.

## El tope de memoria

GD descomprime a memoria: 4 bytes por píxel. Una imagen de 16 MP son 64 MB antes
de tocar nada, y con `memory_limit` en 128M pasar de ahí es un OOM que se lleva
la request. Por encima de **16 MP** no se comprime — se guarda el original, que
igual está acotado a 4 MB por la validación (`ImageStorage::MAX_KB`).

No es teórico: el modo de 48 MP de un iPhone produce justo eso.

## Lo que necesita el contenedor

`gd` y `exif`, que **no estaban** en el Dockerfile. Las libs de runtime
(`libjpeg-turbo`, `libpng`, `libwebp`, `freetype`) van en el grupo permanente y
sus `-dev` en el virtual: `apk del .build-deps` se lleva los `-dev`, y si el
`.so` de runtime hubiera entrado ahí se iría con ellos.

Antes de desplegar conviene comprobar el build, que no se puede verificar desde
el repo:

```bash
docker build -t nexolu-spa-api:test .
docker run --rm nexolu-spa-api:test php -m | grep -E '^(gd|exif)$'
```

Si faltaran, nada se rompe: el compresor devuelve `null` y las imágenes se
guardan sin comprimir.

## Para publicar en Instagram

Meta **descarga la imagen desde nuestro servidor** al crear la publicación, así
que la URL tiene que ser alcanzable desde internet sin firma ni autenticación.
Con el disco local y `storage:link` puesto, lo es.

Dos límites de Meta a tener en cuenta cuando llegue ese módulo:

- **8 MB por imagen.** Con la compresión no se acerca ni de lejos.
- **Proporción entre 4:5 y 1.91:1.** Una foto muy vertical —un pantallazo, o una
  mano fotografiada de arriba abajo— la rechaza. Todavía no se valida.

## Lo que falta

- **Terminar el disco Spaces**: agregar las dos dependencias y llenar
  `SPACES_CDN_URL`. Meta descargaría del CDN y el droplet no vería ese tráfico.
  La decisión fue salir con el droplet y moverlo después.
- **Comprimir lo ya guardado.** Esto sólo actúa sobre lo que entra de ahora en
  adelante; lo subido antes sigue pesando lo que pesaba.
- **Validar la proporción** contra los límites de Instagram, antes de publicar.
