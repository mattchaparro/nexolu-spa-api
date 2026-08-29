<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Crea o promueve un usuario de plataforma.
 *
 * No hay pantalla para esto a proposito: quien administra todos los negocios
 * se crea desde el servidor, no desde una interfaz que alguien pueda alcanzar.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'superadmin:create {email} {--name=Plataforma} {--password=}';

    protected $description = 'Crea un usuario de plataforma, o promueve uno existente.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->option('password') ?: $this->secret('Contrasena');

        $existing = User::withoutGlobalScope('business')->where('email', $email)->first();

        if ($existing) {
            $existing->update(['is_super_admin' => true, 'is_active' => true]);
            $this->info("Usuario {$email} promovido a plataforma.");

            return self::SUCCESS;
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', Password::min(12)]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Sin business_id: el superadmin no pertenece a ningun negocio, y por
        // eso el scope de BelongsToBusiness no lo filtra.
        User::create([
            'business_id' => null,
            'name' => $this->option('name'),
            'email' => $email,
            'password' => Hash::make($password),
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $this->info("Usuario de plataforma {$email} creado.");

        return self::SUCCESS;
    }
}
