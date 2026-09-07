<?php

namespace Tests\Feature\Migracion;

use App\Services\Migration\LecturaSolamente;
use App\Services\Migration\LegacyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El libro que hace que la migracion se pueda correr todos los dias.
 *
 * La app vieja de Luxury no se apaga el dia de la mudanza: sigue atendiendo
 * clientas mientras el equipo se acostumbra. Asi que el comando se corre hoy
 * con 3.324 atenciones y manana con las 12 de hoy. Todo lo que impide que la
 * segunda corrida duplique las 767 clientas esta en esta clase.
 */
class LegacyMapTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    public function test_una_fila_ya_traida_no_se_vuelve_a_traer(): void
    {
        $negocio = $this->makeBusiness();
        $map = new LegacyMap($negocio->id);

        $this->assertFalse($map->yaExiste('client', 42));

        $map->anotar('client', 42, 900);

        $this->assertTrue($map->yaExiste('client', 42));
        $this->assertSame(900, $map->idNuevo('client', 42));
    }

    public function test_cada_negocio_tiene_su_propio_libro(): void
    {
        /*
         * El id 42 del legacy de un local no tiene nada que ver con el 42 del
         * legacy de otro. Sin esta separacion, migrar un segundo negocio
         * creeria que sus clientas ya estan y no traeria ninguna.
         */
        $uno = $this->makeBusiness();
        $otro = $this->makeBusiness();

        (new LegacyMap($uno->id))->anotar('client', 42, 900);

        $this->assertFalse((new LegacyMap($otro->id))->yaExiste('client', 42));
    }

    public function test_las_entidades_no_se_pisan_entre_si(): void
    {
        // La clienta 7 y el servicio 7 son cosas distintas.
        $negocio = $this->makeBusiness();
        $map = new LegacyMap($negocio->id);

        $map->anotar('client', 7, 100);
        $map->anotar('service', 7, 200);

        $this->assertSame(100, $map->idNuevo('client', 7));
        $this->assertSame(200, $map->idNuevo('service', 7));
    }

    public function test_reanotar_no_duplica_la_fila(): void
    {
        $negocio = $this->makeBusiness();
        $map = new LegacyMap($negocio->id);

        $map->anotar('client', 42, 900, 'huella-vieja');
        $map->anotar('client', 42, 900, 'huella-nueva');

        $this->assertSame(1, DB::table('legacy_map')->where('legacy_id', 42)->count());
        $this->assertSame('huella-nueva', DB::table('legacy_map')->where('legacy_id', 42)->value('fingerprint'));
    }

    public function test_dos_corridas_a_la_vez_no_pueden_crear_la_misma_clienta(): void
    {
        /*
         * La garantia esta en el indice unico del motor, no en el codigo: si
         * alguien deja el comando en un cron y ademas lo corre a mano, las
         * dos corridas compiten y la segunda tiene que chocar, no duplicar.
         */
        $negocio = $this->makeBusiness();

        DB::table('legacy_map')->insert([
            'business_id' => $negocio->id, 'entity' => 'client', 'legacy_id' => 42,
            'new_id' => 900, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('legacy_map')->insert([
            'business_id' => $negocio->id, 'entity' => 'client', 'legacy_id' => 42,
            'new_id' => 901, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Huellas: que se volvio a mirar y que no
    |--------------------------------------------------------------------------
    */

    public function test_una_fila_que_no_cambio_no_se_reescribe(): void
    {
        $negocio = $this->makeBusiness();
        $map = new LegacyMap($negocio->id);

        $huella = LegacyMap::huella(['Ana', '573001112233']);
        $map->anotar('client', 42, 900, $huella);

        $this->assertFalse($map->cambio('client', 42, $huella));
    }

    public function test_una_fila_que_cambio_en_el_legacy_se_detecta(): void
    {
        $negocio = $this->makeBusiness();
        $map = new LegacyMap($negocio->id);

        $map->anotar('client', 42, 900, LegacyMap::huella(['Ana', '573001112233']));

        $this->assertTrue($map->cambio('client', 42, LegacyMap::huella(['Ana', '573009998877'])));
    }

    public function test_una_fila_nunca_traida_no_cuenta_como_cambiada(): void
    {
        // "¿Cambio?" y "¿existe?" son preguntas distintas: mezclarlas haria
        // que la primera corrida creyera que todo esta desactualizado.
        $negocio = $this->makeBusiness();
        $map = new LegacyMap($negocio->id);

        $this->assertFalse($map->cambio('client', 42, LegacyMap::huella(['Ana'])));
    }

    public function test_la_huella_no_depende_del_orden_de_lectura(): void
    {
        $this->assertSame(
            LegacyMap::huella(['Ana', 45000, true]),
            LegacyMap::huella(['Ana', 45000, true]),
        );

        $this->assertNotSame(
            LegacyMap::huella(['Ana', 45000]),
            LegacyMap::huella([45000, 'Ana']),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | El cerrojo sobre la base vieja
    |--------------------------------------------------------------------------
    */

    public function test_no_se_puede_escribirle_a_la_base_vieja(): void
    {
        /*
         * El sistema viejo sigue atendiendo clientas de verdad. Un UPDATE
         * accidental desde el importador no danaria un respaldo: danaria el
         * local, en vivo, un sabado.
         */
        config()->set('database.connections.legacy', config('database.connections.'.config('database.default')));
        DB::purge('legacy');

        LecturaSolamente::proteger('legacy');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('solo lectura');

        DB::connection('legacy')->statement('UPDATE clients SET name = ?', ['nope']);
    }

    public function test_leer_de_la_base_vieja_si_se_puede(): void
    {
        config()->set('database.connections.legacy', config('database.connections.'.config('database.default')));
        DB::purge('legacy');

        LecturaSolamente::proteger('legacy');

        $this->assertSame([], DB::connection('legacy')->select('select 1 where 1 = 0'));
    }
}
