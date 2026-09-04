<?php

namespace Tests\Feature\Social;

use App\Models\Business;
use App\Models\BusinessSocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Models\User;
use App\Services\Social\PostDispatcher;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Publicar en Instagram.
 *
 * EL FLUJO DE META ES EN DOS TIEMPOS —crear un contenedor, publicarlo— y en
 * tres con carrusel. Esa forma es lo que se prueba: un `publish` sin su
 * contenedor, o un carrusel sin el contenedor que agrupa, falla con un código
 * que no dice nada.
 *
 * LO QUE MÁS SE DEFIENDE: que una publicación rechazada NO se pierda. Queda en
 * `failed` con el motivo escrito. Una que desaparece porque un token caducó es
 * peor que una que no salió — nadie sabe que faltó.
 */
class InstagramPublishTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * La `url` se le pasa al disco falso porque si no devuelve rutas
         * relativas, y lo que se prueba acá es lo que Meta recibe — que
         * necesita una URL absoluta para poder descargar la imagen.
         */
        Storage::fake('public', ['url' => 'https://agenda.test/storage']);

        $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00', 'America/Bogota'));

        PermissionCatalog::sync();

        config([
            'services.instagram.graph_url' => 'https://graph.test',
            'services.instagram.graph_version' => 'v21.0',
            'services.instagram.app_id' => 'app-123',
            'services.instagram.app_secret' => 'secreto',
        ]);

        $this->business = $this->makeBusiness();
    }

    /*
    |--------------------------------------------------------------------------
    | El flujo de Meta
    |--------------------------------------------------------------------------
    */

    public function test_una_sola_imagen_va_en_dos_pasos(): void
    {
        $this->metaResponde();
        $this->cuenta();

        Sanctum::actingAs($this->duena());
        $post = $this->publicacion(1);

        $this->postJson("/api/v1/social-posts/{$post->id}/publish")
            ->assertOk()
            ->assertJsonPath('post.status', SocialPost::STATUS_PUBLISHED);

        // Contenedor y publicación: dos llamadas de escritura, en ese orden.
        Http::assertSentCount(3); // media, status_code, media_publish

        $this->assertSame('media-999', $post->fresh()->external_ref);
    }

    public function test_el_texto_va_en_el_contenedor_con_los_hashtags_pegados(): void
    {
        // Instagram no tiene campo de hashtags: van dentro del mismo texto.
        $this->metaResponde();
        $this->cuenta();

        Sanctum::actingAs($this->duena());
        $post = $this->publicacion(1);

        $this->postJson("/api/v1/social-posts/{$post->id}/publish")->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/media')) {
                return false;
            }

            $caption = $request->data()['caption'] ?? '';

            return str_contains($caption, 'Quedó lindo') && str_contains($caption, '#unas');
        });
    }

    public function test_un_carrusel_crea_un_contenedor_por_foto_y_uno_que_los_agrupa(): void
    {
        $this->metaResponde();
        $this->cuenta();

        Sanctum::actingAs($this->duena());
        $post = $this->publicacion(3);

        $this->postJson("/api/v1/social-posts/{$post->id}/publish")->assertOk();

        // Las piezas llevan `is_carousel_item` y NO llevan texto: ponerlo en
        // cada una lo repetiría o lo perdería, según el humor de la API.
        Http::assertSent(fn ($r) => ($r->data()['is_carousel_item'] ?? null) === 'true'
            && ! isset($r->data()['caption']));

        Http::assertSent(fn ($r) => ($r->data()['media_type'] ?? null) === 'CAROUSEL'
            && isset($r->data()['caption']));
    }

    public function test_meta_descarga_la_imagen_asi_que_se_le_manda_una_url(): void
    {
        /*
         * No se le mandan los bytes: Meta va a buscar la imagen a nuestro
         * servidor. Por eso la URL tiene que ser pública, y por eso importa
         * tanto que las imágenes estén comprimidas.
         */
        $this->metaResponde();
        $this->cuenta();

        Sanctum::actingAs($this->duena());
        $post = $this->publicacion(1);

        $this->postJson("/api/v1/social-posts/{$post->id}/publish")->assertOk();

        Http::assertSent(fn ($r) => str_starts_with((string) ($r->data()['image_url'] ?? ''), 'http'));
    }

    /*
    |--------------------------------------------------------------------------
    | Cuando algo sale mal
    |--------------------------------------------------------------------------
    */

    public function test_si_meta_rechaza_la_publicacion_no_se_pierde(): void
    {
        Http::fake(['graph.test/*' => Http::response([
            'error' => ['message' => 'The access token is invalid', 'code' => 190],
        ], 400)]);

        $this->cuenta();

        Sanctum::actingAs($this->duena());
        $post = $this->publicacion(1);

        $this->postJson("/api/v1/social-posts/{$post->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Instagram dijo: The access token is invalid');

        // El motivo QUEDA GUARDADO: quien abra el calendario mañana también
        // tiene que poder saber por qué esa publicación no salió.
        $post = $post->fresh();

        $this->assertNotSame(SocialPost::STATUS_PUBLISHED, $post->status);
        $this->assertStringContainsString('access token', $post->error);
    }

    public function test_un_token_caducado_no_intenta_siquiera(): void
    {
        // Gastar una llamada —y cupo del límite diario— en algo que ya
        // sabemos que falla no ayuda a nadie.
        Http::fake();

        $this->cuenta(expira: CarbonImmutable::now()->subDay());

        Sanctum::actingAs($this->duena());
        $post = $this->publicacion(1);

        $this->postJson("/api/v1/social-posts/{$post->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', 'El permiso de Instagram caducó. Hay que volver a conectar la cuenta.');

        Http::assertNothingSent();
    }

    public function test_sin_cuenta_conectada_se_dice_que_hay_que_copiar_y_pegar(): void
    {
        // No es un error: es el modo por defecto del producto.
        Http::fake();

        Sanctum::actingAs($this->duena());
        $post = $this->publicacion(1);

        $this->postJson("/api/v1/social-posts/{$post->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Este negocio no tiene Instagram conectado. Copia el texto y publícala desde la app.',
            );
    }

    public function test_una_url_relativa_se_rechaza_con_el_motivo_de_verdad(): void
    {
        /*
         * `Storage::url()` devuelve una ruta relativa cuando APP_URL está mal
         * puesta, y Meta no puede descargar eso. Sin esta comprobación llega
         * como un "media download failure" que manda a buscar el problema en
         * la foto en vez de en el `.env` del servidor.
         */
        Http::fake();
        Storage::fake('public', ['url' => '/storage']);

        $this->cuenta();

        Sanctum::actingAs($this->duena());
        $post = $this->publicacion(1);

        $this->postJson("/api/v1/social-posts/{$post->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'La imagen 1 no tiene una dirección pública. Revisa APP_URL en el servidor.',
            );

        Http::assertNothingSent();
    }

    public function test_una_imagen_demasiado_alargada_se_rechaza_antes_de_llamar_a_meta(): void
    {
        /*
         * Instagram sólo acepta entre 4:5 y 1.91:1. Cuando lo rechaza Meta,
         * lo que vuelve es un código en inglés y ya se gastó cupo del límite
         * diario; comprobarlo antes lo convierte en una frase que dice qué
         * hacer.
         */
        Http::fake();
        $this->cuenta();

        Sanctum::actingAs($this->duena());
        $post = $this->publicacion(1, ancho: 600, alto: 1600);

        $this->postJson("/api/v1/social-posts/{$post->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', 'La imagen 1 es demasiado alargada para Instagram. Recórtala más cuadrada (como mucho 4:5).');

        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | El reloj
    |--------------------------------------------------------------------------
    */

    public function test_con_cuenta_conectada_el_reloj_publica_lo_programado(): void
    {
        /*
         * La garantía nunca fue "el sistema no publica": es "nadie publica sin
         * que una persona lo haya leído". Programar ES aprobar, con el texto
         * y la foto adelante.
         */
        $this->metaResponde();
        $this->cuenta();

        $post = $this->publicacion(1, status: SocialPost::STATUS_SCHEDULED, cuando: now()->subMinutes(5));

        $resultado = $this->app->make(PostDispatcher::class)->run($this->business->fresh());

        $this->assertSame(1, $resultado['published']);
        $this->assertSame(SocialPost::STATUS_PUBLISHED, $post->fresh()->status);
    }

    public function test_sin_cuenta_el_reloj_sigue_dejandolas_listas_para_publicar(): void
    {
        // El modo manual no es un estado degradado: es como opera un spa sus
        // primeras semanas, y hay quien no va a querer salir de ahí nunca.
        Http::fake();

        $post = $this->publicacion(1, status: SocialPost::STATUS_SCHEDULED, cuando: now()->subMinutes(5));

        $resultado = $this->app->make(PostDispatcher::class)->run($this->business->fresh());

        $this->assertSame(1, $resultado['ready']);
        $this->assertSame(0, $resultado['published']);
        $this->assertSame(SocialPost::STATUS_READY, $post->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_lo_que_meta_rechaza_queda_marcado_y_no_vuelve_a_la_bandeja(): void
    {
        /*
         * Una propuesta es algo que nadie miró todavía; esto es algo que
         * alguien aprobó y que hay que arreglar. Mezclarlas esconde el
         * problema entre las ideas.
         */
        Http::fake(['graph.test/*' => Http::response(['error' => ['message' => 'Media ratio invalid']], 400)]);

        $this->cuenta();

        $post = $this->publicacion(1, status: SocialPost::STATUS_SCHEDULED, cuando: now()->subMinutes(5));

        $resultado = $this->app->make(PostDispatcher::class)->run($this->business->fresh());

        $this->assertSame(1, $resultado['failed']);

        $post = $post->fresh();

        $this->assertSame(SocialPost::STATUS_FAILED, $post->status);
        $this->assertNotNull($post->error);
    }

    public function test_la_cuenta_apagada_no_publica_pero_no_desconecta(): void
    {
        // Un negocio que quiere dejar de publicar un mes no debería tener que
        // volver a pasar por Meta para volver.
        Http::fake();

        $this->cuenta(activa: false);

        $post = $this->publicacion(1, status: SocialPost::STATUS_SCHEDULED, cuando: now()->subMinutes(5));

        $resultado = $this->app->make(PostDispatcher::class)->run($this->business->fresh());

        $this->assertSame(1, $resultado['ready']);
        $this->assertSame(SocialPost::STATUS_READY, $post->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | El token no se filtra
    |--------------------------------------------------------------------------
    */

    public function test_el_token_no_sale_en_ninguna_respuesta(): void
    {
        $this->cuenta();

        Sanctum::actingAs($this->duena());

        $respuesta = $this->getJson('/api/v1/social-posts')->assertOk();

        $this->assertStringNotContainsString('token-secreto', $respuesta->getContent());
    }

    public function test_el_token_queda_cifrado_en_la_base(): void
    {
        // Un `select *` de soporte, un backup en un portátil o un volcado para
        // depurar no pueden dejar legible la llave para publicar como alguien.
        $this->cuenta();

        $crudo = DB::table('business_social_accounts')
            ->value('access_token');

        $this->assertNotSame('token-secreto', $crudo);
        $this->assertStringNotContainsString('token-secreto', (string) $crudo);
    }

    /*
    |--------------------------------------------------------------------------
    | Ayudas
    |--------------------------------------------------------------------------
    */

    /** Meta contestando que todo bien, en los tres pasos. */
    private function metaResponde(): void
    {
        Http::fake([
            'graph.test/*/media_publish' => Http::response(['id' => 'media-999']),
            'graph.test/*/media' => Http::sequence()
                ->push(['id' => 'cont-1'])
                ->push(['id' => 'cont-2'])
                ->push(['id' => 'cont-3'])
                ->push(['id' => 'cont-4'])
                ->whenEmpty(Http::response(['id' => 'cont-n'])),
            'graph.test/*' => Http::response(['status_code' => 'FINISHED']),
        ]);
    }

    private function cuenta(
        ?CarbonImmutable $expira = null,
        bool $activa = true,
    ): BusinessSocialAccount {
        return BusinessSocialAccount::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'provider' => BusinessSocialAccount::PROVIDER_INSTAGRAM,
            'external_id' => '178414',
            'username' => 'luxurynails',
            'access_token' => 'token-secreto',
            'token_expires_at' => $expira ?? CarbonImmutable::now()->addDays(50),
            'is_active' => $activa,
        ]);
    }

    private function duena(): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Dueña',
            'email' => uniqid().'@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'is_owner' => true,
        ]);

        PermissionCatalog::applyRole($user, PermissionCatalog::ROLE_ADMIN);

        return $user->fresh();
    }

    private function publicacion(
        int $imagenes,
        string $status = SocialPost::STATUS_DRAFT,
        ?\DateTimeInterface $cuando = null,
        int $ancho = 1080,
        int $alto = 1080,
    ): SocialPost {
        $post = SocialPost::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'status' => $status,
            'source' => SocialPost::SOURCE_MANUAL,
            'angle' => SocialPost::ANGLE_WORK,
            'caption' => 'Quedó lindo.',
            'hashtags' => ['#unas', '#bogota'],
            'scheduled_for' => $cuando,
        ]);

        for ($i = 0; $i < $imagenes; $i++) {
            SocialPostImage::withoutGlobalScopes()->create([
                'business_id' => $this->business->id,
                'social_post_id' => $post->id,
                'image_path' => $this->imagenGuardada($ancho, $alto),
                'position' => $i,
            ]);
        }

        return $post->fresh('images');
    }

    /** Una imagen de verdad en el disco: la proporción se mide del archivo. */
    private function imagenGuardada(int $ancho, int $alto): string
    {
        $img = imagecreatetruecolor($ancho, $alto);
        ob_start();
        imagejpeg($img, null, 70);
        $bytes = ob_get_clean();
        imagedestroy($img);

        $path = 'negocios/'.$this->business->id.'/publicaciones/'.uniqid().'.jpg';
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }
}
