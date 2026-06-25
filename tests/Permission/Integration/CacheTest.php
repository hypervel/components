<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;

class CacheTest extends TestCase
{
    protected PermissionRegistrar $registrar;

    protected int $cacheRunCount = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrar = $this->app->make(PermissionRegistrar::class);
        $this->registrar->forgetCachedPermissions();

        DB::connection()->enableQueryLog();
    }

    public function testItCanCacheThePermissions(): void
    {
        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheRunCount);
    }

    public function testItFlushesTheCacheWhenCreatingAPermission(): void
    {
        $this->app->make(Permission::class)->create(['name' => 'new']);

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheRunCount);
    }

    public function testItFlushesTheCacheWhenUpdatingAPermission(): void
    {
        $permission = $this->app->make(Permission::class)->create(['name' => 'new']);

        $permission->name = 'other name';
        $permission->save();

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheRunCount);
    }

    public function testItFlushesTheCacheWhenCreatingARole(): void
    {
        $this->app->make(Role::class)->create(['name' => 'new']);

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheRunCount);
    }

    public function testItFlushesTheCacheWhenUpdatingARole(): void
    {
        $role = $this->app->make(Role::class)->create(['name' => 'new']);

        $role->name = 'other name';
        $role->save();

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheRunCount);
    }

    public function testItShouldNotFlushTheCacheWhenRemovingAPermissionFromAUser(): void
    {
        $this->testUser->givePermissionTo('edit-articles');

        $this->registrar->getPermissions();

        $this->testUser->revokePermissionTo('edit-articles');

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount(0);
    }

    public function testItShouldNotFlushTheCacheWhenRemovingARoleFromAUser(): void
    {
        $this->testUser->assignRole('testRole');

        $this->registrar->getPermissions();

        $this->testUser->removeRole('testRole');

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount(0);
    }

    public function testItFlushesTheCacheWhenRemovingARoleFromAPermission(): void
    {
        $this->testUserPermission->assignRole('testRole');

        $this->registrar->getPermissions();

        $this->testUserPermission->removeRole('testRole');

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheRunCount);
    }

    public function testItFlushesTheCacheWhenAssigningAPermissionToARole(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheRunCount);
    }

    public function testItShouldNotFlushTheCacheOnUserCreation(): void
    {
        $this->registrar->getPermissions();

        User::create(['email' => 'new']);

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount(0);
    }

    public function testItFlushesTheCacheWhenGivingAPermissionToARole(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheRunCount);
    }

    public function testItUsesTheCacheForHasPermissionTo(): void
    {
        $this->testUserRole->givePermissionTo(['edit-articles', 'edit-news', 'Edit News']);
        $this->testUser->assignRole('testRole');
        $this->testUser->loadMissing('roles', 'permissions');

        $this->resetQueryCount();
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertQueryCount(0);

        $this->resetQueryCount();
        $this->assertTrue($this->testUser->hasPermissionTo('edit-news'));
        $this->assertQueryCount(0);

        $this->resetQueryCount();
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertQueryCount(0);

        $this->resetQueryCount();
        $this->assertTrue($this->testUser->hasPermissionTo('Edit News'));
        $this->assertQueryCount(0);
    }

    public function testItDifferentiatesTheCacheByGuardName(): void
    {
        $this->app->make(Permission::class)->create(['name' => 'web']);
        $this->testUserRole->givePermissionTo(['edit-articles', 'web']);
        $this->testUser->assignRole('testRole');
        $this->testUser->loadMissing('roles', 'permissions');

        $this->resetQueryCount();
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles', 'web'));
        $this->assertQueryCount(0);

        $this->resetQueryCount();

        $this->expectException(PermissionDoesNotExist::class);

        $this->testUser->hasPermissionTo('edit-articles', 'admin');
    }

    public function testItUsesTheCacheForGetAllPermissions(): void
    {
        $this->testUserRole->givePermissionTo($expected = ['edit-articles', 'edit-news']);
        $this->testUser->assignRole('testRole');
        $this->testUser->loadMissing('roles.permissions', 'permissions');

        $this->resetQueryCount();
        $this->registrar->getPermissions();
        $this->assertQueryCount(0);

        $this->resetQueryCount();
        $actual = $this->testUser->getAllPermissions()->pluck('name')->sort()->values();

        $this->assertEquals(collect($expected), $actual);
        $this->assertQueryCount(0);
    }

    public function testItStoresRoleAttributesOnceWhileHydratingPermissionPivots(): void
    {
        $this->testUserRole->givePermissionTo(['edit-articles', 'edit-news']);
        $permissions = $this->registrar->getPermissions();
        $roles = $permissions->flatMap->roles;

        $this->assertNotSame($roles[0], $roles[1]);
        $this->assertSame($roles[0]->getKey(), $roles[1]->getKey());
        $this->assertNotSame(
            $roles[0]->pivot->getAttribute($this->registrar->pivotPermission),
            $roles[1]->pivot->getAttribute($this->registrar->pivotPermission),
        );

        $payload = $this->registrar->getCacheRepository()->get($this->registrar->cacheKey);

        $matchingRoles = array_filter(
            $payload['roles'],
            fn (array $role): bool => $role['attributes'][$this->testUserRole->getKeyName()] === $this->testUserRole->getKey(),
        );

        $this->assertCount(1, $matchingRoles);
    }

    public function testItCanResetTheCacheWithArtisanCommand(): void
    {
        Artisan::call('permission:create-permission', ['name' => 'new-permission']);

        $permissionClass = $this->app->make(Permission::class);

        $this->assertCount(1, $permissionClass::where('name', 'new-permission')->get());

        $this->resetQueryCount();
        $this->registrar->getPermissions();
        $this->assertQueryCount($this->cacheRunCount);

        Artisan::call('permission:cache-reset');

        $this->resetQueryCount();
        $this->registrar->getPermissions();
        $this->assertQueryCount($this->cacheRunCount);
    }

    protected function resetQueryCount(): void
    {
        DB::flushQueryLog();
    }

    protected function assertQueryCount(int $expected): void
    {
        $this->assertCount($expected, DB::getQueryLog());
    }
}
