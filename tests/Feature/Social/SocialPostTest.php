<?php

namespace Tests\Feature\Social;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientPhoto;
use App\Models\Location;
use App\Models\Service;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El calendario de publicaciones, desde el panel.
 *
 * LO QUE MAS SE DEFIENDE ACA ES EL CONSENTIMIENTO. Un modulo de
 * publicaciones montado sobre una base que ya tiene fotos de las manos de
 * doscientas clientas es, sin cuidado, una maquina de publicar fotos que
 * nadie autorizo -- y el error no se ve, porque las fotos quedan preciosas.
 * Media docena de las pruebas de abajo existen solo para que esa puerta no se
 * abra por descuido en un refactor.
 *
 * LO SEGUNDO: que el reloj NO PUBLIQUE. Es la clase de linea que alguien
 * agrega con la mejor intencion -- "ya que esta programada, mandala" -- y hay
 * una prueba que falla si aparece.
 */
class SocialPostTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Service $manicure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-14 08:00', 'America/Bogota'));

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();
        $this->manicure = $this->makeService($this->business, 60, [], name: 'Manicure semipermanente');
    }

    /*
    |--------------------------------------------------------------------------
    | El consentimiento
    |--------------------------------------------------------------------------
    */

    public function test_una_foto_sin_permiso_de_la_clienta_no_entra_a_una_publicacion(): void
    {
        Sanctum::actingAs($this->duena());

        $foto = $this->foto(consentida: false);

        $this->postJson('/api/v1/social-posts/from-photos', [
            'client_photo_ids' => [$foto->id],
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Alguna de esas fotos no se puede publicar. Falta el permiso de la clienta.',
            );

        $this->assertSame(0, SocialPost::withoutGlobalScopes()->count());
    }

    public function test_con_el_permiso_anotado_la_foto_si_entra(): void
    {
        Sanctum::actingAs($this->duena());

        $foto = $this->foto(consentida: true);

        $this->postJson('/api/v1/social-posts/from-photos', [
            'client_photo_ids' => [$foto->id],
        ])
            ->assertCreated()
            ->assertJsonPath('post.angle', SocialPost::ANGLE_WORK)
            ->assertJsonPath('post.images.0.from_client_photo', true);
    }

    public function test_la_foto_de_otro_negocio_se_rechaza_igual_que_una_sin_permiso(): void
    {
        // Mismo mensaje a proposito: decir "esa foto existe pero no es tuya"
        // ya es contar algo del otro negocio.
        $otro = $this->makeBusiness();
        $ajena = $this->foto(consentida: true, business: $otro);

        Sanctum::actingAs($this->duena());

        $this->postJson('/api/v1/social-posts/from-photos', [
            'client_photo_ids' => [$ajena->id],
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Alguna de esas fotos no se puede publicar. Falta el permiso de la clienta.',
            );
    }

    public function test_el_listado_de_fotos_publicables_solo_trae_las_consentidas(): void
    {
        $this->foto(consentida: false);
        $consentida = $this->foto(consentida: true);

        Sanctum::actingAs($this->duena());

        $r = $this->getJson('/api/v1/social-posts/photo-pool')->assertOk();

        $this->assertCount(1, $r->json('photos'));
        $this->assertSame($consentida->id, $r->json('photos.0.id'));
    }

    public function test_el_listado_de_fotos_publicables_no_dice_de_quien_son(): void
    {
        $this->foto(consentida: true, nombre: 'Carolina');

        Sanctum::actingAs($this->duena());

        $r = $this->getJson('/api/v1/social-posts/photo-pool')->assertOk();

        // Quien arma la publicacion no necesita el nombre, y no darlo es mas
        // barato que confiar en que no se use.
        $this->assertStringNotContainsString('Carolina', $r->getContent());
    }

    public function test_retirar_el_permiso_descarta_lo_que_todavia_no_ha_salido(): void
    {
        Sanctum::actingAs($this->duena());

        $foto = $this->foto(consentida: true);

        $programada = $this->publicacion([
            'caption' => 'Mira este degradado.',
            'status' => SocialPost::STATUS_SCHEDULED,
            'scheduled_for' => now()->addDay(),
        ], [$foto->id]);

        $publicada = $this->publicacion([
            'caption' => 'La de la semana pasada.',
            'status' => SocialPost::STATUS_PUBLISHED,
            'published_at' => now()->subWeek(),
        ], [$foto->id]);

        $this->postJson("/api/v1/clients/photos/{$foto->id}/marketing-consent", ['allowed' => false])
            ->assertOk()
            ->assertJsonPath('marketing_consent', false);

        $this->assertSame(SocialPost::STATUS_DISCARDED, $programada->fresh()->status);

        // Lo que ya salio no se puede despublicar desde aca, y decir lo
        // contrario en la base seria mentirle al negocio.
        $this->assertSame(SocialPost::STATUS_PUBLISHED, $publicada->fresh()->status);
    }

    public function test_el_permiso_se_puede_anotar_al_subir_la_foto(): void
    {
        Sanctum::actingAs($this->duena());

        $clienta = $this->clienta('Valentina');

        $r = $this->postJson("/api/v1/clients/{$clienta->id}/photos", [
            'photo' => UploadedFile::fake()->image('unas.jpg'),
            'marketing_consent' => true,
        ])->assertCreated();

        $this->assertTrue($r->json('marketing_consent'));
    }

    public function test_sin_decir_nada_la_foto_queda_sin_permiso(): void
    {
        // El silencio no es un permiso.
        Sanctum::actingAs($this->duena());

        $clienta = $this->clienta('Valentina');

        $r = $this->postJson("/api/v1/clients/{$clienta->id}/photos", [
            'photo' => UploadedFile::fake()->image('unas.jpg'),
        ])->assertCreated();

        $this->assertFalse($r->json('marketing_consent'));
    }

    /*
    |--------------------------------------------------------------------------
    | El carrusel
    |--------------------------------------------------------------------------
    | Un trabajo de uñas se muestra de frente, de lado y con la mano cerrada:
    | son tres fotos de lo mismo. Con una sola imagen, el negocio elige cuál y
    | descarta las otras dos.
    */

    public function test_una_publicacion_nace_con_varias_fotos_en_orden(): void
    {
        Sanctum::actingAs($this->duena());

        $frente = $this->foto(consentida: true);
        $lado = $this->foto(consentida: true);

        $r = $this->postJson('/api/v1/social-posts/from-photos', [
            'client_photo_ids' => [$frente->id, $lado->id],
        ])->assertCreated();

        $this->assertSame(
            [$frente->id, $lado->id],
            array_column($r->json('post.images'), 'client_photo_id'),
        );
    }

    public function test_la_portada_es_la_primera_imagen(): void
    {
        // Es la única que se ve en la cuadrícula del perfil, y la que decide
        // si alguien abre la publicación.
        Sanctum::actingAs($this->duena());

        $portada = $this->foto(consentida: true);
        $segunda = $this->foto(consentida: true);

        $r = $this->postJson('/api/v1/social-posts/from-photos', [
            'client_photo_ids' => [$portada->id, $segunda->id],
        ])->assertCreated();

        $this->assertSame($r->json('post.images.0.url'), $r->json('post.image_url'));
    }

    public function test_basta_una_foto_sin_permiso_para_rechazar_el_carrusel_entero(): void
    {
        // No se cuela "las que sí": guardar tres y publicar dos sin decir
        // nada es exactamente cómo se publica una foto que nadie autorizó.
        Sanctum::actingAs($this->duena());

        $ok = $this->foto(consentida: true);
        $sinPermiso = $this->foto(consentida: false);

        $this->postJson('/api/v1/social-posts/from-photos', [
            'client_photo_ids' => [$ok->id, $sinPermiso->id],
        ])->assertStatus(422);

        $this->assertSame(0, SocialPost::withoutGlobalScopes()->count());
    }

    public function test_no_caben_mas_de_diez_imagenes(): void
    {
        // El tope es de Instagram: con once rechaza la publicación entera.
        Sanctum::actingAs($this->duena());

        $yaEstaban = collect(range(1, 6))->map(fn () => $this->foto(consentida: true)->id)->all();
        $post = $this->publicacion([], $yaEstaban);

        $nuevas = collect(range(1, 5))->map(fn () => $this->foto(consentida: true)->id)->all();

        // Seis que ya estaban más cinco nuevas son once: el tope se cuenta
        // sobre el TOTAL, no sobre cada lista por separado.
        $this->postJson("/api/v1/social-posts/{$post->id}", ['client_photo_ids' => $nuevas])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Un carrusel de Instagram admite hasta 10 imágenes.');
    }

    public function test_guardar_solo_el_texto_no_vacia_el_carrusel(): void
    {
        // Es el error silencioso que este módulo no se puede permitir: la
        // dueña corrige una tilde y la publicación se queda sin fotos.
        Sanctum::actingAs($this->duena());

        $foto = $this->foto(consentida: true);
        $post = $this->publicacion([], [$foto->id]);

        $this->postJson("/api/v1/social-posts/{$post->id}", ['caption' => 'Otro texto.'])
            ->assertOk()
            ->assertJsonCount(1, 'post.images');
    }

    public function test_reordenar_es_volver_a_guardar_la_lista(): void
    {
        Sanctum::actingAs($this->duena());

        $a = $this->foto(consentida: true);
        $b = $this->foto(consentida: true);
        $post = $this->publicacion([], [$a->id, $b->id]);

        $ids = $post->images->pluck('id')->all();

        $r = $this->postJson("/api/v1/social-posts/{$post->id}", [
            'keep_image_ids' => [$ids[1], $ids[0]],
        ])->assertOk();

        $this->assertSame(
            [$b->id, $a->id],
            array_column($r->json('post.images'), 'client_photo_id'),
        );
    }

    public function test_quitar_una_imagen_no_borra_la_foto_de_la_ficha(): void
    {
        /*
         * La foto es de la clienta y sigue en su historial. Que quitarla de
         * una publicación le vaciara la ficha sería un efecto que nadie
         * espera de un botón que dice "quitar".
         */
        Sanctum::actingAs($this->duena());

        $foto = $this->foto(consentida: true);
        $post = $this->publicacion([], [$foto->id]);

        $this->postJson("/api/v1/social-posts/{$post->id}", ['keep_image_ids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'post.images');

        $this->assertNotNull($foto->fresh());
    }

    public function test_retirar_el_permiso_de_una_foto_deja_las_otras(): void
    {
        // Un carrusel de tres al que una clienta le retira el permiso sigue
        // teniendo dos. Descartarlo entero sería castigar al negocio por una
        // decisión que no es suya.
        Sanctum::actingAs($this->duena());

        $suya = $this->foto(consentida: true);
        $otra = $this->foto(consentida: true);
        $post = $this->publicacion(['status' => SocialPost::STATUS_SCHEDULED], [$suya->id, $otra->id]);

        $this->postJson("/api/v1/clients/photos/{$suya->id}/marketing-consent", ['allowed' => false])
            ->assertOk();

        $post = $post->fresh('images');

        $this->assertSame(SocialPost::STATUS_SCHEDULED, $post->status);
        $this->assertSame([$otra->id], $post->images->pluck('client_photo_id')->all());
    }

    public function test_si_era_la_unica_imagen_la_publicacion_se_descarta(): void
    {
        // Dejarla vacía en la bandeja la haría ver como un error del sistema,
        // y alguien le pondría otra imagen y la sacaría igual.
        Sanctum::actingAs($this->duena());

        $foto = $this->foto(consentida: true);
        $post = $this->publicacion(['status' => SocialPost::STATUS_SCHEDULED], [$foto->id]);

        $this->postJson("/api/v1/clients/photos/{$foto->id}/marketing-consent", ['allowed' => false])
            ->assertOk();

        $this->assertSame(SocialPost::STATUS_DISCARDED, $post->fresh()->status);
    }

    public function test_la_publicacion_hereda_el_servicio_de_la_cita_que_produjo_la_foto(): void
    {
        /*
         * Es lo que este módulo sabe y un calendario suelto no: quien escriba
         * el texto ya sabe de qué servicio habla.
         */
        Sanctum::actingAs($this->duena());

        $foto = $this->fotoDeUnServicio();

        $this->postJson('/api/v1/social-posts/from-photos', ['client_photo_ids' => [$foto->id]])
            ->assertCreated()
            ->assertJsonPath('post.service_name', $this->manicure->name);
    }

    /*
    |--------------------------------------------------------------------------
    | Aprobar y publicar
    |--------------------------------------------------------------------------
    */

    public function test_no_se_programa_algo_a_lo_que_le_falta_el_texto_o_la_imagen(): void
    {
        Sanctum::actingAs($this->duena());

        $sinNada = $this->publicacion(['angle' => SocialPost::ANGLE_GAP]);

        $this->postJson("/api/v1/social-posts/{$sinNada->id}/schedule", [
            'scheduled_for' => now()->addDay()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le falta el texto o la imagen.');

        $this->assertSame(SocialPost::STATUS_DRAFT, $sinNada->fresh()->status);
    }

    public function test_programar_deja_escrito_quien_aprobo(): void
    {
        $duena = $this->duena();
        Sanctum::actingAs($duena);

        $post = $this->completa();

        $this->postJson("/api/v1/social-posts/{$post->id}/schedule", [
            'scheduled_for' => now()->addDay()->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('post.status', SocialPost::STATUS_SCHEDULED)
            ->assertJsonPath('post.approved_by', $duena->name);

        $this->assertSame($duena->id, $post->fresh()->approved_by_user_id);
    }

    public function test_una_publicacion_que_ya_salio_no_se_reescribe(): void
    {
        Sanctum::actingAs($this->duena());

        $post = $this->completa(['status' => SocialPost::STATUS_PUBLISHED, 'published_at' => now()]);

        $this->postJson("/api/v1/social-posts/{$post->id}", ['caption' => 'Otro texto.'])
            ->assertStatus(422);

        $this->assertNotSame('Otro texto.', $post->fresh()->caption);
    }

    public function test_una_publicacion_que_ya_salio_no_se_descarta(): void
    {
        Sanctum::actingAs($this->duena());

        $post = $this->completa(['status' => SocialPost::STATUS_PUBLISHED, 'published_at' => now()]);

        $this->deleteJson("/api/v1/social-posts/{$post->id}")->assertStatus(422);

        $this->assertSame(SocialPost::STATUS_PUBLISHED, $post->fresh()->status);
    }

    public function test_descartar_no_borra(): void
    {
        // Que una idea se haya descartado es justo lo que impide que el
        // planificador la vuelva a proponer manana.
        Sanctum::actingAs($this->duena());

        $post = $this->publicacion([]);

        $this->deleteJson("/api/v1/social-posts/{$post->id}")->assertOk();

        $this->assertSame(SocialPost::STATUS_DISCARDED, $post->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | El asistente
    |--------------------------------------------------------------------------
    */

    public function test_el_asistente_escribe_el_texto_y_los_hashtags_quedan_aparte(): void
    {
        config()->set('services.ia_core.api_key', 'llave-ia');
        config()->set('services.ia_core.base_url', 'http://ia-core.test');

        Http::fake(['ia-core.test/*' => Http::response([
            'text' => "Este degradado se hizo esta mañana.\nEscríbenos y te contamos.\n\n#unas #manicure #bogota",
        ])]);

        Sanctum::actingAs($this->duena());

        $post = $this->publicacion(['angle' => SocialPost::ANGLE_WORK]);

        $r = $this->postJson("/api/v1/social-posts/{$post->id}/compose")->assertOk();

        $this->assertStringContainsString('degradado', $r->json('post.caption'));
        $this->assertStringNotContainsString('#unas', $r->json('post.caption'));
        $this->assertSame(['#unas', '#manicure', '#bogota'], $r->json('post.hashtags'));
        $this->assertTrue($r->json('post.written_by_assistant'));
    }

    public function test_el_encargo_al_asistente_no_lleva_el_nombre_de_la_clienta(): void
    {
        config()->set('services.ia_core.api_key', 'llave-ia');
        config()->set('services.ia_core.base_url', 'http://ia-core.test');

        Http::fake(['ia-core.test/*' => Http::response(['text' => "Quedó lindo.\n\n#unas"])]);

        Sanctum::actingAs($this->duena());

        $foto = $this->foto(consentida: true, nombre: 'Carolina');
        $post = $this->publicacion(['angle' => SocialPost::ANGLE_WORK], [$foto->id]);

        $this->postJson("/api/v1/social-posts/{$post->id}/compose")->assertOk();

        /*
         * El permiso fue para la foto de sus unas, no para que su nombre
         * viaje a un servicio de IA. Un dato que no se manda no se puede
         * filtrar despues, y por eso se prueba el CUERPO de la llamada y no
         * el texto que vuelve.
         */
        Http::assertSent(function ($request) {
            return ! str_contains(json_encode($request->data()), 'Carolina');
        });
    }

    public function test_reescribir_el_texto_a_mano_le_quita_la_etiqueta_del_asistente(): void
    {
        Sanctum::actingAs($this->duena());

        $post = $this->publicacion(['caption' => 'Lo del modelo.', 'composed_at' => now()]);

        $r = $this->postJson("/api/v1/social-posts/{$post->id}", ['caption' => 'Lo que quiero decir yo.'])
            ->assertOk();

        // "Lo escribió el asistente" al lado de un texto que la duena
        // reescribio entera es una etiqueta que miente.
        $this->assertFalse($r->json('post.written_by_assistant'));
    }

    public function test_si_el_asistente_no_contesta_el_modulo_sigue_sirviendo(): void
    {
        config()->set('services.ia_core.api_key', 'llave-ia');
        config()->set('services.ia_core.base_url', 'http://ia-core.test');

        Http::fake(['ia-core.test/*' => Http::response([], 500)]);

        Sanctum::actingAs($this->duena());

        $post = $this->publicacion([]);

        $this->postJson("/api/v1/social-posts/{$post->id}/compose")
            ->assertStatus(422)
            ->assertJsonPath('message', 'El asistente no está disponible ahora. Puedes escribir el texto a mano.');

        // Y a mano si se puede: un modulo que se cae porque un servicio de IA
        // esta caido no es un modulo.
        $this->postJson("/api/v1/social-posts/{$post->id}", ['caption' => 'Lo escribo yo.'])
            ->assertOk()
            ->assertJsonPath('post.caption', 'Lo escribo yo.');
    }

    /*
    |--------------------------------------------------------------------------
    | Quien entra
    |--------------------------------------------------------------------------
    */

    public function test_recepcion_no_maneja_las_redes_del_negocio(): void
    {
        // Necesita la ficha de la clienta para su trabajo, y eso no la
        // convierte en la voz publica del spa.
        Sanctum::actingAs($this->empleada());

        $this->getJson('/api/v1/social-posts')->assertForbidden();
    }

    public function test_sin_la_bandera_el_modulo_no_existe(): void
    {
        $this->business->update([
            'feature_flags' => array_merge(BusinessFeaturePresets::full(), ['social_posts' => false]),
        ]);

        Sanctum::actingAs($this->duena());

        $this->getJson('/api/v1/social-posts')->assertForbidden();
    }

    public function test_la_publicacion_de_la_sede_ajena_no_se_edita_por_id(): void
    {
        $cedritos = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Cedritos', 'slug' => 'cedritos-'.uniqid(),
            'is_primary' => false, 'is_active' => true,
        ]);

        $ajena = $this->publicacion(['location_id' => $cedritos->id]);

        // Una encargada de Chapinero con el permiso del modulo: el listado se
        // la esconde, y sin este guardia el id directo la editaria igual.
        $encargada = $this->empleada();
        $encargada->givePermissionTo('publicaciones.gestionar');
        $encargada->locations()->sync([$this->business->primaryLocation()->id]);

        Sanctum::actingAs($encargada->fresh());

        $this->postJson("/api/v1/social-posts/{$ajena->id}", ['caption' => 'Mia ahora.'])
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Ayudas
    |--------------------------------------------------------------------------
    */

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

    private function empleada(): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Encargada',
            'email' => uniqid().'@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'is_owner' => false,
        ]);

        PermissionCatalog::applyRole($user, PermissionCatalog::ROLE_RECEPTION);

        return $user->fresh();
    }

    private function clienta(string $nombre, ?Business $business = null): Client
    {
        return Client::create([
            'business_id' => ($business ?? $this->business)->id,
            'name' => $nombre,
            'phone' => '+57300'.random_int(1000000, 9999999),
            'is_active' => true,
        ]);
    }

    private function foto(bool $consentida, string $nombre = 'Clienta', ?Business $business = null): ClientPhoto
    {
        $business ??= $this->business;

        return ClientPhoto::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'client_id' => $this->clienta($nombre, $business)->id,
            'image_path' => 'negocios/'.$business->id.'/trabajos/'.uniqid().'.jpg',
            'taken_at' => now()->subDay(),
            'marketing_consent_at' => $consentida ? now()->subDay() : null,
        ]);
    }

    /** Una foto colgada de una cita real, para que herede su servicio. */
    private function fotoDeUnServicio(): ClientPhoto
    {
        $cuando = CarbonImmutable::now()->subDay();

        $clienta = $this->clienta('Carolina');

        $cita = Appointment::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'location_id' => $this->business->primaryLocation()?->id,
            'client_id' => $clienta->id,
            'client_name' => $clienta->name,
            'starts_at' => $cuando,
            'ends_at' => $cuando->addHour(),
            'status' => Appointment::STATUS_COMPLETED,
            'source' => 'panel',
        ]);

        $item = AppointmentItem::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'appointment_id' => $cita->id,
            'service_id' => $this->manicure->id,
            'resource_id' => $this->makeResource($this->business, 'Maria')->id,
            'starts_at' => $cuando,
            'ends_at' => $cuando->addHour(),
            'service_starts_at' => $cuando,
            'service_ends_at' => $cuando->addHour(),
            'price' => 50000,
        ]);

        return ClientPhoto::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'client_id' => $clienta->id,
            'appointment_item_id' => $item->id,
            'image_path' => 'negocios/'.$this->business->id.'/trabajos/'.uniqid().'.jpg',
            'taken_at' => $cuando,
            'marketing_consent_at' => $cuando,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $photoIds  Fotos de ficha que la ilustran, en orden.
     * @param  bool  $conImagenSuelta  Una imagen propia, para que esté completa.
     */
    private function publicacion(
        array $attributes,
        array $photoIds = [],
        bool $conImagenSuelta = false,
    ): SocialPost {
        $post = SocialPost::withoutGlobalScopes()->create($attributes + [
            'business_id' => $this->business->id,
            'status' => SocialPost::STATUS_DRAFT,
            'source' => SocialPost::SOURCE_MANUAL,
            'angle' => SocialPost::ANGLE_FREE,
        ]);

        $position = 0;

        foreach ($photoIds as $photoId) {
            SocialPostImage::withoutGlobalScopes()->create([
                'business_id' => $this->business->id,
                'social_post_id' => $post->id,
                'client_photo_id' => $photoId,
                'position' => $position++,
            ]);
        }

        if ($conImagenSuelta) {
            SocialPostImage::withoutGlobalScopes()->create([
                'business_id' => $this->business->id,
                'social_post_id' => $post->id,
                'image_path' => 'negocios/'.$this->business->id.'/publicaciones/'.uniqid().'.jpg',
                'position' => $position++,
            ]);
        }

        return $post->fresh('images');
    }

    /**
     * Una con texto e imagen: lo minimo para poder programarse.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function completa(array $attributes = []): SocialPost
    {
        return $this->publicacion(
            $attributes + [
                'caption' => 'Quedan horas el jueves.',
                'service_id' => $this->manicure->id,
            ],
            conImagenSuelta: true,
        );
    }
}
