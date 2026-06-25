<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Permission\Contracts\Permission;
use Hypervel\Tests\Permission\Fixtures\Models\Manager;
use Hypervel\Tests\Permission\TestCase;

class MultipleGuardsTest extends TestCase
{
    public function testItCanGivePermissionsToAModelUsedByMultipleGuards(): void
    {
        $this->testUser->givePermissionTo($this->app->make(Permission::class)::create([
            'name' => 'do_this',
            'guard_name' => 'web',
        ]));

        $this->testUser->givePermissionTo($this->app->make(Permission::class)::create([
            'name' => 'do_that',
            'guard_name' => 'api',
        ]));

        $this->assertTrue($this->testUser->checkPermissionTo('do_this', 'web'));
        $this->assertTrue($this->testUser->checkPermissionTo('do_that', 'api'));
        $this->assertFalse($this->testUser->checkPermissionTo('do_that', 'web'));
    }

    public function testGateCanGrantPermissionByGuardName(): void
    {
        $this->testUser->givePermissionTo($this->app->make(Permission::class)::create([
            'name' => 'do_this',
            'guard_name' => 'web',
        ]));

        $this->testUser->givePermissionTo($this->app->make(Permission::class)::create([
            'name' => 'do_that',
            'guard_name' => 'api',
        ]));

        $this->assertTrue($this->testUser->can('do_this', 'web'));
        $this->assertTrue($this->testUser->can('do_that', 'api'));
        $this->assertFalse($this->testUser->can('do_that', 'web'));
        $this->assertTrue($this->testUser->cannot('do_that', 'web'));
        $this->assertTrue($this->testUser->canAny(['do_this', 'do_that'], 'web'));

        $this->testAdminRole->givePermissionTo($this->testAdminPermission);
        $this->testAdmin->assignRole($this->testAdminRole);

        $this->assertTrue($this->testAdmin->hasPermissionTo($this->testAdminPermission));
        $this->assertTrue($this->testAdmin->can('admin-permission'));
        $this->assertTrue($this->testAdmin->can('admin-permission', 'admin'));
        $this->assertTrue($this->testAdmin->cannot('admin-permission', 'web'));
        $this->assertTrue($this->testAdmin->cannot('non-existing-permission'));
        $this->assertTrue($this->testAdmin->cannot('non-existing-permission', 'web'));
        $this->assertTrue($this->testAdmin->cannot('non-existing-permission', 'admin'));
        $this->assertTrue($this->testAdmin->cannot(['admin-permission', 'non-existing-permission'], 'web'));
        $this->assertFalse($this->testAdmin->can('edit-articles', 'web'));
        $this->assertFalse($this->testAdmin->can('edit-articles', 'admin'));
        $this->assertTrue($this->testUser->cannot('edit-articles', 'admin'));
        $this->assertTrue($this->testUser->cannot('admin-permission', 'admin'));
        $this->assertTrue($this->testUser->cannot('admin-permission', 'web'));
    }

    public function testItHonorsGuardNameMethodWhenOverridingGuardNameProperty(): void
    {
        $user = Manager::create(['email' => 'manager@test.com']);
        $user->givePermissionTo($this->app->make(Permission::class)::create([
            'name' => 'do_jwt',
            'guard_name' => 'jwt',
        ]));

        $this->assertTrue($user->checkPermissionTo('do_jwt', 'jwt'));
        $this->assertTrue($user->hasPermissionTo('do_jwt', 'jwt'));
        $this->assertFalse($user->checkPermissionTo('do_jwt', 'web'));
    }
}
