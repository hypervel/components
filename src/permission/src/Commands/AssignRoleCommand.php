<?php

declare(strict_types=1);

namespace Hypervel\Permission\Commands;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\PermissionRegistrar;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'permission:assign-role')]
class AssignRoleCommand extends Command
{
    protected ?string $signature = 'permission:assign-role
        {name : The name of the role}
        {userId : The ID of the user to assign the role to}
        {guard? : The name of the guard}
        {userModelNamespace=App\Models\User : The fully qualified class name of the user model}
        {--team-id=}';

    protected string $description = 'Assign a role to a user';

    /**
     * Execute the console command.
     */
    public function handle(PermissionRegistrar $permissionRegistrar): int
    {
        $roleName = (string) $this->argument('name');
        $userId = (string) $this->argument('userId');
        $guardName = $this->argument('guard');
        $userModelClass = $this->argument('userModelNamespace');

        if (! $permissionRegistrar->teams && $this->option('team-id')) {
            $this->warn('Teams feature disabled, argument --team-id has no effect. Either enable it in permissions config file or remove --team-id parameter');

            return self::SUCCESS;
        }

        if (! is_string($userModelClass) || ! class_exists($userModelClass)) {
            $this->error("User model class [{$userModelClass}] does not exist.");

            return self::FAILURE;
        }

        if (! is_subclass_of($userModelClass, Model::class)) {
            $this->error("User model class [{$userModelClass}] must extend [" . Model::class . '].');

            return self::FAILURE;
        }

        $user = $userModelClass::query()->whereKey($userId)->first();

        if (! $user) {
            $this->error("User with ID {$userId} not found.");

            return self::FAILURE;
        }

        if (! method_exists($user, 'assignRole')) {
            $this->error("User model class [{$userModelClass}] must use the HasRoles trait.");

            return self::FAILURE;
        }

        $teamIdAux = getPermissionsTeamId();
        setPermissionsTeamId($this->option('team-id') ?: null);

        $roleClass = $permissionRegistrar->getRoleClass();

        try {
            $role = $roleClass::findOrCreate($roleName, is_string($guardName) ? $guardName : null);

            $assignRole = Closure::fromCallable([$user, 'assignRole']);
            $assignRole($role);
        } finally {
            setPermissionsTeamId($teamIdAux);
        }

        $this->info("Role `{$role->name}` assigned to user ID {$userId} successfully.");

        return self::SUCCESS;
    }
}
