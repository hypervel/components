<?php

declare(strict_types=1);

namespace Hypervel\Permission\Commands;

use Hypervel\Console\Command;
use Hypervel\Permission\PermissionRegistrar;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'permission:create-permission')]
class CreatePermissionCommand extends Command
{
    protected ?string $signature = 'permission:create-permission
                {name : The name of the permission}
                {guard? : The name of the guard}';

    protected string $description = 'Create a permission';

    /**
     * Execute the console command.
     */
    public function handle(PermissionRegistrar $permissionRegistrar): int
    {
        $permissionClass = $permissionRegistrar->getPermissionClass();
        $guard = $this->argument('guard');

        $permission = $permissionClass::findOrCreate((string) $this->argument('name'), is_string($guard) ? $guard : null);

        $this->info("Permission `{$permission->name}` " . ($permission->wasRecentlyCreated ? 'created' : 'already exists'));

        return self::SUCCESS;
    }
}
