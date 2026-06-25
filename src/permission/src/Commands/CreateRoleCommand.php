<?php

declare(strict_types=1);

namespace Hypervel\Permission\Commands;

use Hypervel\Console\Command;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'permission:create-role')]
class CreateRoleCommand extends Command
{
    protected ?string $signature = 'permission:create-role
        {name : The name of the role}
        {guard? : The name of the guard}
        {permissions? : A list of permissions to assign to the role, separated by | }
        {--team-id=}';

    protected string $description = 'Create a role';

    /**
     * Execute the console command.
     */
    public function handle(PermissionRegistrar $permissionRegistrar): int
    {
        if (! $permissionRegistrar->teams && $this->option('team-id')) {
            $this->warn('Teams feature disabled, argument --team-id has no effect. Either enable it in permissions config file or remove --team-id parameter');

            return self::SUCCESS;
        }

        $roleClass = $permissionRegistrar->getRoleClass();
        $guard = $this->argument('guard');
        $teamIdAux = getPermissionsTeamId();

        try {
            setPermissionsTeamId($this->option('team-id') ?: null);

            $role = $roleClass::findOrCreate((string) $this->argument('name'), is_string($guard) ? $guard : null);
        } finally {
            setPermissionsTeamId($teamIdAux);
        }

        $teamsKey = $permissionRegistrar->teamsKey;
        if ($permissionRegistrar->teams && $this->option('team-id') && is_null($role->{$teamsKey})) {
            $this->warn("Role `{$role->name}` already exists on the global team; argument --team-id has no effect");
        }

        $role->givePermissionTo($this->makePermissions($permissionRegistrar, $this->argument('permissions')));

        $this->info("Role `{$role->name}` " . ($role->wasRecentlyCreated ? 'created' : 'updated'));

        return self::SUCCESS;
    }

    /**
     * Make the given permission models.
     */
    protected function makePermissions(PermissionRegistrar $permissionRegistrar, mixed $permissions): ?Collection
    {
        if (! is_string($permissions) || $permissions === '') {
            return null;
        }

        $permissionClass = $permissionRegistrar->getPermissionClass();
        $guard = $this->argument('guard');

        $permissions = explode('|', $permissions);

        $models = [];

        foreach ($permissions as $permission) {
            $models[] = $permissionClass::findOrCreate(trim($permission), is_string($guard) ? $guard : null);
        }

        return new Collection($models);
    }
}
