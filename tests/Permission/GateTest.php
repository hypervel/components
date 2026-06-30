<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;

class GateTest extends TestCase
{
    public function testItDeniesMissingPermissionsThroughGate(): void
    {
        $this->assertFalse($this->testUser->can('edit-articles'));
    }

    public function testOtherGateBeforeCallbacksCanGrantMissingPermissions(): void
    {
        $this->assertFalse($this->testUser->can('edit-articles'));

        $this->app->make(Gate::class)->before(fn (): bool => true);

        $this->assertTrue($this->testUser->can('edit-articles'));
    }

    public function testGateAfterCallbackCanGrantDeniedPrivileges(): void
    {
        $this->assertFalse($this->testUser->can('edit-articles'));

        $this->app->make(Gate::class)->after(fn (): bool => true);

        $this->assertTrue($this->testUser->can('edit-articles'));
    }

    public function testItAllowsDirectPermissionsThroughGate(): void
    {
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertTrue($this->testUser->can('edit-articles'));
        $this->assertFalse($this->testUser->can('non-existing-permission'));
        $this->assertFalse($this->testUser->can('admin-permission'));
    }

    public function testItAllowsRolePermissionsThroughGate(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);
        $this->testUser->assignRole($this->testUserRole);

        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
        $this->assertTrue($this->testUser->can('edit-articles'));
        $this->assertFalse($this->testUser->can('non-existing-permission'));
        $this->assertFalse($this->testUser->can('admin-permission'));
    }

    public function testItAllowsRolePermissionsForUsersWithDifferentGuardsThroughGate(): void
    {
        $this->testAdminRole->givePermissionTo($this->testAdminPermission);
        $this->testAdmin->assignRole($this->testAdminRole);

        $this->assertTrue($this->testAdmin->hasPermissionTo($this->testAdminPermission));
        $this->assertTrue($this->testAdmin->can('admin-permission'));
        $this->assertFalse($this->testAdmin->can('non-existing-permission'));
        $this->assertFalse($this->testAdmin->can('edit-articles'));
    }

    public function testItAllowsEnumPermissionsThroughGate(): void
    {
        $this->app->make(Permission::class)::findOrCreate(TestRolePermissionsEnum::ViewArticles);

        $this->assertFalse($this->testUser->can(TestRolePermissionsEnum::ViewArticles->value));
        $this->assertFalse($this->testUser->canAny([TestRolePermissionsEnum::ViewArticles->value, 'missing']));

        $this->testUser->givePermissionTo(TestRolePermissionsEnum::ViewArticles);

        $this->assertTrue($this->testUser->hasPermissionTo(TestRolePermissionsEnum::ViewArticles));
        $this->assertTrue($this->testUser->can(TestRolePermissionsEnum::ViewArticles->value));
        $this->assertTrue($this->testUser->canAny([TestRolePermissionsEnum::ViewArticles->value, 'missing']));
    }

    public function testForbiddenPermissionDeniesGatePermission(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->giveForbiddenTo('edit-articles');

        $this->assertFalse($this->testUser->can('edit-articles'));
    }
}
