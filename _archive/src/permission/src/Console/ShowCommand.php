<?php

declare(strict_types=1);

namespace Hypervel\Permission\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\TableCell;

#[AsCommand(name: 'permission:show')]
class ShowCommand extends Command
{
    protected ?string $signature = 'permission:show
                                    {guard? : The name of the guard}
                                    {style? : The display style (default|borderless|compact|box)}';

    protected string $description = 'Show a table of all permissions and roles';

    public function handle()
    {
        $permissionClass = new (config('permission.models.permission'))();
        $roleClass = new (config('permission.models.role'))();

        $style = $this->argument('style') ?: 'default';
        $guard = $this->argument('guard');
        if ($guard) {
            $guards = Collection::make([$guard]);
        } else {
            $guards = $permissionClass::pluck('guard_name')->merge($roleClass::pluck('guard_name'))->unique();
        }
        foreach ($guards as $guard) {
            $this->info("Guard: {$guard}");

            $roles = $roleClass::where('guard_name', $guard)
                ->with('permissions')
                ->orderBy('name')->get()->mapWithKeys(fn ($role) => [
                    $role->name => [
                        'permissions' => $role->permissions->pluck($permissionClass->getKeyName()),
                    ],
                ]);

            $permissions = $permissionClass::whereGuardName($guard)->orderBy('name')->pluck(
                'name',
                $permissionClass->getKeyName()
            );

            $body = $permissions->map(
                fn ($permission, $id) => $roles->map(
                    fn (array $role_data) => $role_data['permissions']->contains($id) ? ' ✔' : ' ·'
                )->prepend($permission)
            );

            $this->table(
                array_merge(
                    $roles->keys()->map(function ($val) {
                        $name = explode('_', $val);
                        array_pop($name);

                        return implode('_', $name);
                    })
                        ->prepend(new TableCell(''))->toArray(),
                ),
                $body->toArray(),
                $style
            );
        }
    }
}
