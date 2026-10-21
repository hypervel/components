<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Permission\Middleware\RoleMiddleware;
use Hypervel\Permission\Middleware\RoleOrPermissionMiddleware;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;

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
        $role = Role::findOrCreate(PureRole::StaffWriter);
        $permission = Permission::findOrCreate(PurePermission::PublishArticles);

        $role->givePermissionTo(PurePermission::PublishArticles);
        $this->testUser->assignRole(PureRole::StaffWriter);

        $this->assertSame('StaffWriter', $role->name);
        $this->assertTrue($role->is(Role::findByName(PureRole::StaffWriter)));
        $this->assertTrue($permission->is(Permission::findByName(PurePermission::PublishArticles)));
        $this->assertTrue($this->testUser->hasRole(PureRole::StaffWriter));
        $this->assertTrue($this->testUser->hasPermissionTo(PurePermission::PublishArticles));
        $this->assertTrue($role->hasPermissionTo(PurePermission::PublishArticles));
        $this->assertTrue($permission->roles->contains($role));
    }

    public function testPureUnitEnumsCanBeUsedWithMiddlewareUsingMethods(): void
    {
        $this->assertSame(
            RoleMiddleware::class . ':StaffWriter',
            RoleMiddleware::using(PureRole::StaffWriter),
        );
        $this->assertSame(
            PermissionMiddleware::class . ':PublishArticles',
            PermissionMiddleware::using(PurePermission::PublishArticles),
        );
        $this->assertSame(
            RoleOrPermissionMiddleware::class . ':StaffWriter|PublishArticles',
            RoleOrPermissionMiddleware::using([PureRole::StaffWriter, PurePermission::PublishArticles]),
        );
    }
}
