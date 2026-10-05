<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\TestCase;

class GateTest extends TestCase
{
    public function testItCanDetermineIfAUserDoesNotHaveAPermission(): void
    {
        $this->assertFalse($this->testUser->can('edit-articles'));
    }

    public function testItAllowsOtherGateBeforeCallbacksToRunIfAUserDoesNotHaveAPermission(): void
    {
        $this->assertFalse($this->testUser->can('edit-articles'));

        $this->app->make(Gate::class)->before(function (): bool {
            // this Gate-before intercept overrides everything to true ... like a typical Super-Admin might use
            return true;
        });

        $this->assertTrue($this->testUser->can('edit-articles'));
    }

    public function testItAllowsGateAfterCallbackToGrantDeniedPrivileges(): void
    {
        $this->assertFalse($this->testUser->can('edit-articles'));

        $this->app->make(Gate::class)->after(fn (): bool => true);

        $this->assertTrue($this->testUser->can('edit-articles'));
    }

    public function testItCanDetermineIfAUserHasADirectPermission(): void
    {
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertTrue($this->testUser->can('edit-articles'));
        $this->assertFalse($this->testUser->can('non-existing-permission'));
        $this->assertFalse($this->testUser->can('admin-permission'));
    }

    public function testItCanDetermineIfAUserHasADirectPermissionUsingEnums(): void
    {
        $enum = TestRolePermissionsEnum::ViewArticles;

        $permission = $this->app->make(Permission::class)->findOrCreate($enum->value, 'web');

        $this->assertFalse($this->testUser->can($enum->value));
        $this->assertFalse($this->testUser->canAny([$enum->value, 'some other permission']));

        $this->testUser->givePermissionTo($enum);

        $this->assertTrue($this->testUser->hasPermissionTo($enum));
        $this->assertTrue($this->testUser->can($enum->value));
        $this->assertTrue($this->testUser->canAny([$enum->value, 'some other permission']));
    }

    public function testItCanDetermineIfAUserHasAPermissionThroughRoles(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);

        $this->testUser->assignRole($this->testUserRole);

        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
        $this->assertTrue($this->testUser->can('edit-articles'));
        $this->assertFalse($this->testUser->can('non-existing-permission'));
        $this->assertFalse($this->testUser->can('admin-permission'));
    }

    public function testItCanDetermineIfAUserWithADifferentGuardHasAPermissionWhenUsingRoles(): void
    {
        $this->testAdminRole->givePermissionTo($this->testAdminPermission);

        $this->testAdmin->assignRole($this->testAdminRole);

        $this->assertTrue($this->testAdmin->hasPermissionTo($this->testAdminPermission));
        $this->assertTrue($this->testAdmin->can('admin-permission'));
        $this->assertFalse($this->testAdmin->can('non-existing-permission'));
        $this->assertFalse($this->testAdmin->can('edit-articles'));
    }

    public function testDeniedPermissionDeniesGatePermission(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->denyPermissionTo('edit-articles');

        $this->assertFalse($this->testUser->can('edit-articles'));
    }
}
