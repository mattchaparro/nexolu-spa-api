<?php

namespace Tests\Feature\Instagram;

use App\Models\Business;
use App\Models\InstagramStory;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Historias de Instagram desde el panel.
 *
 * Existe la tabla porque la API de Meta NO programa: publicar es una llamada
 * en el momento. Y guarda el resultado porque hay tres formas de fallar --
 * la imagen no es JPEG, el token caduco, Meta esta caido -- y sin el motivo
 * escrito quien administra ve "no se publico" y no puede hacer nada.
 */
class InstagramStoryTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::now('America/Bogota')->startOfDay()->setTime(10, 0));

        Storage::fake('public');
        PermissionCatalog::sync();

        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');

        $this->business = $this->makeBusiness();

        $duena = User::create([
            'business_id' => $this->business->id,
            'name' => 'Dueña',
            'email' => 'duena@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'is_owner' => true,
        ]);
        PermissionCatalog::applyRole($duena, PermissionCatalog::ROLE_ADMIN);

        Sanctum::actingAs($duena->fresh());
    }

    private function crear(array $overrides = []): TestResponse
    {
        return $this->post('/api/v1/instagram/stories', array_merge([
            'image' => UploadedFile::fake()->image('historia.jpg', 1080, 1920),
        ], $overrides));
    }

    private function commsResponde(array $body, int $status = 200): void
    {
        Http::fake(['comms.test/*' => Http::response($body, $status)]);
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que Instagram exige
    |--------------------------------------------------------------------------
    */

    public function test_solo_acepta_jpeg(): void
    {
        /*
         * No es una preferencia: Meta solo publica JPEG. Aceptar un PNG aquí
         * dejaría programar una historia para el sábado que va a fallar el
         * sábado, cuando ya nadie está mirando.
         */
        $this->post('/api/v1/instagram/stories', [
            'image' => UploadedFile::fake()->image('historia.png', 1080, 1920),
        ])->assertStatus(422);
    }

    public function test_no_se_puede_programar_para_el_pasado(): void
    {
        $this->crear(['scheduled_at' => now()->subHour()->toIso8601String()])
            ->assertStatus(422);
    }

    public function test_las_menciones_se_guardan_sin_arroba(): void
    {
        // Meta espera el username pelado.
        $this->crear(['mentions' => ['@luxurynails']])->assertCreated();

        $this->assertSame(['luxurynails'], InstagramStory::withoutGlobalScopes()->first()->mentions);
    }

    /*
    |--------------------------------------------------------------------------
    | Publicar
    |--------------------------------------------------------------------------
    */

    public function test_publicar_guarda_el_id_que_devuelve_meta(): void
    {
        $this->commsResponde(['status' => 'published', 'media_id' => 'media-123']);

        $id = $this->crear()->assertCreated()->json('data.id');

        $this->postJson("/api/v1/instagram/stories/{$id}/publish")->assertOk();

        $historia = InstagramStory::withoutGlobalScopes()->find($id);
        $this->assertSame(InstagramStory::STATUS_PUBLISHED, $historia->status);
        $this->assertSame('media-123', $historia->media_id);
    }

    public function test_un_rechazo_de_meta_deja_el_motivo_escrito(): void
    {
        /*
         * Entre "la imagen no es JPEG" y "el token caducó" está la diferencia
         * entre volver a subir la foto y renovar la credencial en Meta. Un
         * "falló" a secas no le sirve a nadie.
         */
        $this->commsResponde(['status' => 'failed', 'error' => 'The access token has expired']);

        $id = $this->crear()->json('data.id');

        $r = $this->postJson("/api/v1/instagram/stories/{$id}/publish")->assertStatus(422);

        $this->assertStringContainsString('expired', $r->json('message'));
        $historia = InstagramStory::withoutGlobalScopes()->find($id);
        $this->assertSame(InstagramStory::STATUS_FAILED, $historia->status);
        $this->assertStringContainsString('expired', $historia->error);
    }

    public function test_una_que_fallo_se_puede_reintentar(): void
    {
        // Si el motivo era el token, renovarlo y reintentar es el camino: no
        // deberia haber que volver a subir la imagen.
        // Una secuencia, no dos `fake`: un segundo `Http::fake` no reemplaza
        // al primero y seguiría ganando la respuesta de fallo.
        Http::fake([
            'comms.test/*' => Http::sequence()
                ->push(['status' => 'failed', 'error' => 'token expired'])
                ->push(['status' => 'published', 'media_id' => 'media-9']),
        ]);

        $id = $this->crear()->json('data.id');
        $this->postJson("/api/v1/instagram/stories/{$id}/publish")->assertStatus(422);
        $this->postJson("/api/v1/instagram/stories/{$id}/publish")->assertOk();

        $this->assertSame(
            InstagramStory::STATUS_PUBLISHED,
            InstagramStory::withoutGlobalScopes()->find($id)->status,
        );
    }

    public function test_una_ya_publicada_no_se_vuelve_a_publicar(): void
    {
        // Ya está en Instagram: "republicarla" desde acá crearía una segunda.
        $this->commsResponde(['status' => 'published', 'media_id' => 'media-1']);
        $id = $this->crear()->json('data.id');
        $this->postJson("/api/v1/instagram/stories/{$id}/publish")->assertOk();

        $this->postJson("/api/v1/instagram/stories/{$id}/publish")->assertStatus(422);
    }

    public function test_si_comms_esta_caido_queda_el_motivo_no_una_excepcion(): void
    {
        Http::fake(['comms.test/*' => Http::response([], 500)]);

        $id = $this->crear()->json('data.id');
        $this->postJson("/api/v1/instagram/stories/{$id}/publish")->assertStatus(422);

        $this->assertNotNull(InstagramStory::withoutGlobalScopes()->find($id)->error);
    }

    /*
    |--------------------------------------------------------------------------
    | Programarla
    |--------------------------------------------------------------------------
    */

    public function test_el_comando_publica_solo_las_que_ya_tocaban(): void
    {
        $this->commsResponde(['status' => 'published', 'media_id' => 'media-1']);

        $ahora = $this->crear(['scheduled_at' => now()->addMinutes(2)->toIso8601String()])->json('data.id');
        $manana = $this->crear(['scheduled_at' => now()->addDay()->toIso8601String()])->json('data.id');

        $this->travel(5)->minutes();
        $this->artisan('historias:publicar')->assertSuccessful();

        $this->assertSame(
            InstagramStory::STATUS_PUBLISHED,
            InstagramStory::withoutGlobalScopes()->find($ahora)->status,
        );
        $this->assertSame(
            InstagramStory::STATUS_SCHEDULED,
            InstagramStory::withoutGlobalScopes()->find($manana)->status,
        );
    }

    public function test_una_historia_de_otro_negocio_no_existe(): void
    {
        $otro = $this->makeBusiness();
        $ajenaId = DB::table('instagram_stories')->insertGetId([
            'business_id' => $otro->id,
            'image_path' => 'x.jpg',
            'status' => InstagramStory::STATUS_DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/instagram/stories/{$ajenaId}/publish")->assertNotFound();
        $this->postJson("/api/v1/instagram/stories/{$ajenaId}/cancel")->assertNotFound();
    }
}
