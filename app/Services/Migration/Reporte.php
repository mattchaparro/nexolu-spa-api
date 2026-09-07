<?php

namespace App\Services\Migration;

/**
 * Lo que la corrida hizo, para que quien la ejecuta no tenga que creerle.
 *
 * Separa CREADOS de ACTUALIZADOS de SALTADOS a proposito: en la primera
 * corrida casi todo se crea, y en las diarias casi todo se salta. Si el
 * numero de creados de una corrida diaria se dispara, algo del mapeo se
 * rompio y se ve de un vistazo en vez de descubrirse con 700 clientas
 * duplicadas.
 */
class Reporte
{
    /** @var array<string, array{creados:int, actualizados:int, saltados:int}> */
    private array $lineas = [];

    /** @var list<array{paso:string, mensaje:string}> */
    private array $avisos = [];

    private function linea(string $paso): void
    {
        $this->lineas[$paso] ??= ['creados' => 0, 'actualizados' => 0, 'saltados' => 0];
    }

    public function creado(string $paso, int $cuantos = 1): void
    {
        $this->linea($paso);
        $this->lineas[$paso]['creados'] += $cuantos;
    }

    public function actualizado(string $paso, int $cuantos = 1): void
    {
        $this->linea($paso);
        $this->lineas[$paso]['actualizados'] += $cuantos;
    }

    public function saltado(string $paso, int $cuantos = 1): void
    {
        $this->linea($paso);
        $this->lineas[$paso]['saltados'] += $cuantos;
    }

    /**
     * Algo que un humano tiene que mirar: una clienta sin telefono, una cita
     * futura que choca, un metodo de pago que no existe aca.
     *
     * NO detiene la corrida. Frenar 3.329 atenciones porque tres filas estan
     * raras deja al negocio sin historial; traer 3.326 y una lista de tres
     * pendientes es lo util.
     */
    public function aviso(string $paso, string $mensaje): void
    {
        $this->avisos[] = ['paso' => $paso, 'mensaje' => $mensaje];
    }

    /** @return list<array{0:string,1:int,2:int,3:int}> */
    public function filas(): array
    {
        $filas = [];

        foreach ($this->lineas as $paso => $n) {
            $filas[] = [$paso, $n['creados'], $n['actualizados'], $n['saltados']];
        }

        return $filas;
    }

    /** @return list<array{paso:string, mensaje:string}> */
    public function avisos(): array
    {
        return $this->avisos;
    }

    public function hayAvisos(): bool
    {
        return $this->avisos !== [];
    }
}
