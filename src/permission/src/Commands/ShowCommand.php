<?php

declare(strict_types=1);

namespace Hypervel\Permission\Commands;

use Hypervel\Console\Command;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\TableCell;

#[AsCommand(name: 'permission:show')]
class ShowCommand extends Command
{
    protected ?string $signature = 'permission:show
            {guard? : The name of the guard}
            {style? : The display style (default|borderless|compact|box)}';

    protected string $description = 'Show a table of roles and permissions per guard';

    /**
     * Execute the console command.
     */
    public function handle(PermissionRegistrar $permissionRegistrar): int
    {
        $permissionClass = $permissionRegistrar->getPermissionClass();
        $roleClass = $permissionRegistrar->getRoleClass();
        $permissionKey = (new $permissionClass)->getKeyName();
        $teamsEnabled = Config::teamsEnabled();
        $teamKey = Config::teamForeignKey();

        $style = (string) ($this->argument('style') ?? 'default');
        $guard = $this->argument('guard');

        if ($guard !== null && $guard !== '') {
            $guards = Collection::make([(string) $guard]);
        } else {
            $guards = $permissionClass::query()
                ->pluck('guard_name')
                ->merge($roleClass::query()->pluck('guard_name'))
                ->unique();
        }

        foreach ($guards as $guard) {
            $this->info("Guard: {$guard}");

            $roles = $roleClass::query()
                ->where('guard_name', $guard)
                ->with('permissions')
                ->when($teamsEnabled, fn ($q) => $q->orderBy($teamKey))
                ->orderBy('name')->get()->mapWithKeys(fn ($role) => [
                    $role->name . '_' . ($teamsEnabled ? (string) ($role->{$teamKey} ?? '') : '') => [
                        'permissions' => $role->permissions->pluck($permissionKey),
                        $teamKey => $teamsEnabled ? $role->{$teamKey} : null,
                    ],
                ]);

            $permissions = $permissionClass::query()
                ->where('guard_name', $guard)
                ->orderBy('name')
                ->pluck('name', $permissionKey);

            $body = $permissions->map(
                fn ($permission, $id) => $roles->map(
                    fn (array $role_data) => $role_data['permissions']->contains($id) ? ' ✔' : ' ·'
                )->prepend($permission)
            );

            $teams = null;

            if ($teamsEnabled) {
                $teams = $roles->groupBy($teamKey)->map(
                    fn ($group, $id) => new TableCell('Team ID: ' . ($id === null || $id === '' ? 'NULL' : $id), ['colspan' => $group->count()])
                );
            }

            $roleHeaders = $roles->keys()->map(function ($val) {
                $name = explode('_', $val);
                array_pop($name);

                return implode('_', $name);
            })->toArray();
            array_unshift($roleHeaders, new TableCell(''));

            $teamHeaders = $teams ? $teams->toArray() : [];
            if ($teamHeaders !== []) {
                array_unshift($teamHeaders, new TableCell(''));
            }

            $this->table(
                array_merge($teamHeaders, $roleHeaders),
                $body->toArray(),
                $style
            );
        }

        return self::SUCCESS;
    }
}
