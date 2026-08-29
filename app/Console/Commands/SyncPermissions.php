<?php

namespace App\Console\Commands;

use App\Support\PermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Crea los permisos y roles del catalogo que falten en la base.';

    public function handle(): int
    {
        PermissionCatalog::sync();

        $this->info(sprintf(
            'Sincronizados %d permisos y %d roles.',
            count(PermissionCatalog::names()),
            count(PermissionCatalog::roles()),
        ));

        return self::SUCCESS;
    }
}
