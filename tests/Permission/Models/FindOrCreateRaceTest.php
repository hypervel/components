<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Models;

use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Exceptions\PermissionAlreadyExists;
use Hypervel\Permission\Exceptions\RoleAlreadyExists;
use Hypervel\Tests\Permission\TestCase;

class FindOrCreateRaceTest extends TestCase
{
    protected array $connectionsToTransact = [];

    public function testPermissionFindOrCreateReturnsExistingRowAfterUniqueRace(): void
    {
        $permissionClass = $this->app->make(PermissionContract::class);
        $insertedId = null;

        $permissionClass::creating(static function ($permission) use ($permissionClass, &$insertedId): void {
            if ($permission->getAttribute('name') !== 'raced-permission' || $insertedId !== null) {
                return;
            }

            $insertedId = $permissionClass::query()->insertGetId([
                'name' => 'raced-permission',
                'guard_name' => 'web',
            ]);
        });

        $permission = $permissionClass::findOrCreate('raced-permission');

        $this->assertSame($insertedId, $permission->getKey());
        $this->assertSame('raced-permission', $permission->name);
        $this->assertSame('web', $permission->guard_name);
    }

    public function testRoleFindOrCreateReturnsExistingRowAfterUniqueRace(): void
    {
        $roleClass = $this->app->make(RoleContract::class);
        $insertedId = null;

        $roleClass::creating(static function ($role) use ($roleClass, &$insertedId): void {
            if ($role->getAttribute('name') !== 'raced-role' || $insertedId !== null) {
                return;
            }

            $insertedId = $roleClass::query()->insertGetId([
                'name' => 'raced-role',
                'guard_name' => 'web',
            ]);
        });

        $role = $roleClass::findOrCreate('raced-role');

        $this->assertSame($insertedId, $role->getKey());
        $this->assertSame('raced-role', $role->name);
        $this->assertSame('web', $role->guard_name);
    }

    public function testPermissionCreateThrowsPackageExceptionAfterUniqueRace(): void
    {
        $permissionClass = $this->app->make(PermissionContract::class);

        $permissionClass::creating(static function ($permission) use ($permissionClass): void {
            if ($permission->getAttribute('name') !== 'raced-permission-create') {
                return;
            }

            $permissionClass::query()->insert([
                'name' => 'raced-permission-create',
                'guard_name' => 'web',
            ]);
        });

        $this->expectException(PermissionAlreadyExists::class);

        $permissionClass::create(['name' => 'raced-permission-create']);
    }

    public function testRoleCreateThrowsPackageExceptionAfterUniqueRace(): void
    {
        $roleClass = $this->app->make(RoleContract::class);

        $roleClass::creating(static function ($role) use ($roleClass): void {
            if ($role->getAttribute('name') !== 'raced-role-create') {
                return;
            }

            $roleClass::query()->insert([
                'name' => 'raced-role-create',
                'guard_name' => 'web',
            ]);
        });

        $this->expectException(RoleAlreadyExists::class);

        $roleClass::create(['name' => 'raced-role-create']);
    }
}
