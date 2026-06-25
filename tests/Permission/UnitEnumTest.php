<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;

enum PurePermission
{
    case PublishArticles;
}

enum PureRole
{
    case StaffWriter;
}

class UnitEnumTest extends TestCase
{
    public function testPureUnitEnumsCanBeUsedForRoleAndPermissionAssignments(): void
    {
        $role = $this->app->make(RoleContract::class)::create(['name' => PureRole::StaffWriter->name]);
        $permission = $this->app->make(PermissionContract::class)::create(['name' => PurePermission::PublishArticles->name]);

        $role->givePermissionTo(PurePermission::PublishArticles);
        $this->testUser->assignRole(PureRole::StaffWriter);

        $this->assertTrue($this->testUser->hasRole(PureRole::StaffWriter));
        $this->assertTrue($this->testUser->hasPermissionTo(PurePermission::PublishArticles));
        $this->assertTrue($role->hasPermissionTo(PurePermission::PublishArticles));
        $this->assertTrue($permission->roles->contains($role));
    }
}
