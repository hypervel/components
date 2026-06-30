<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Models;

use Hypervel\Permission\Models\Permission;
use Hypervel\Tests\Permission\TestCase;

class WildcardRoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('permission.enable_wildcard_permission', true);
        $this->flushPermissionState();

        Permission::create(['name' => 'other-permission']);
        Permission::create(['name' => 'wrong-guard-permission', 'guard_name' => 'admin']);
    }

    public function testItCanBeGivenAPermission(): void
    {
        Permission::create(['name' => 'posts.*']);
        $this->testUserRole->givePermissionTo('posts.*');

        $this->assertTrue($this->testUserRole->hasPermissionTo('posts.create'));
    }

    public function testItCanBeGivenMultiplePermissionsUsingAnArray(): void
    {
        Permission::create(['name' => 'posts.*']);
        Permission::create(['name' => 'news.*']);

        $this->testUserRole->givePermissionTo(['posts.*', 'news.*']);

        $this->assertTrue($this->testUserRole->hasPermissionTo('posts.create'));
        $this->assertTrue($this->testUserRole->hasPermissionTo('news.create'));
    }

    public function testItCanBeGivenMultiplePermissionsUsingMultipleArguments(): void
    {
        Permission::create(['name' => 'posts.*']);
        Permission::create(['name' => 'news.*']);

        $this->testUserRole->givePermissionTo('posts.*', 'news.*');

        $this->assertTrue($this->testUserRole->hasPermissionTo('posts.edit.123'));
        $this->assertTrue($this->testUserRole->hasPermissionTo('news.view.1'));
    }

    public function testItCanBeGivenAPermissionUsingObjects(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);

        $this->assertTrue($this->testUserRole->hasPermissionTo($this->testUserPermission));
    }

    public function testItReturnsFalseIfItDoesNotHaveThePermission(): void
    {
        $this->assertFalse($this->testUserRole->hasPermissionTo('other-permission'));
    }

    public function testItReturnsFalseIfPermissionDoesNotExist(): void
    {
        $this->assertFalse($this->testUserRole->hasPermissionTo('doesnt-exist'));
    }

    public function testItReturnsFalseIfItDoesNotHaveAPermissionObject(): void
    {
        $permission = Permission::findByName('other-permission');

        $this->assertFalse($this->testUserRole->hasPermissionTo($permission));
    }

    public function testItCreatesPermissionObjectWithFindOrCreateIfItDoesNotHaveAPermissionObject(): void
    {
        $permission = Permission::findOrCreate('another-permission');

        $this->assertFalse($this->testUserRole->hasPermissionTo($permission));

        $this->testUserRole->givePermissionTo($permission);
        $this->testUserRole = $this->testUserRole->fresh();

        $this->assertTrue($this->testUserRole->hasPermissionTo('another-permission'));
    }

    public function testItReturnsFalseWhenAPermissionOfTheWrongGuardIsPassedIn(): void
    {
        $permission = Permission::findByName('wrong-guard-permission', 'admin');

        $this->assertFalse($this->testUserRole->hasPermissionTo($permission));
    }
}
