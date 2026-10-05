<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Cache\DatabaseStore;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;
use Mockery as m;

class CacheTest extends TestCase
{
    protected PermissionRegistrar $registrar;

    protected int $cacheInitCount = 0;

    protected int $cacheLoadCount = 0;

    // Permissions, role pivots and the role catalog. Upstream doesn't cache roles,
    // so its count is 2 and its role lookups query the database instead.
    protected int $cacheRunCount = 3;

    protected function setUpInCoroutine(): void
    {
        $this->registrar = $this->app->make(PermissionRegistrar::class);

        $this->registrar->forgetCachedPermissions();

        DB::connection()->enableQueryLog();

        if ($this->registrar->getCacheStore() instanceof DatabaseStore) {
            // A cold entry is read once, then filled under a cache lock: acquire, read again,
            // refresh, write and release.
            $this->cacheInitCount = 1;
            $this->cacheLoadCount = 5;
        }
    }

    public function testItCanCacheThePermissions(): void
    {
        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheInitCount + $this->cacheLoadCount + $this->cacheRunCount);
    }

    public function testItFlushesTheCacheWhenCreatingAPermission(): void
    {
        $this->app->make(Permission::class)->create(['name' => 'new']);

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheInitCount + $this->cacheLoadCount + $this->cacheRunCount);
    }

    public function testItFlushesTheCacheWhenUpdatingAPermission(): void
    {
        $permission = $this->app->make(Permission::class)->create(['name' => 'new']);

        $permission->name = 'other name';
        $permission->save();

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheInitCount + $this->cacheLoadCount + $this->cacheRunCount);
    }

    public function testItFlushesTheCacheWhenCreatingARole(): void
    {
        $this->app->make(Role::class)->create(['name' => 'new']);

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheInitCount + $this->cacheLoadCount + $this->cacheRunCount);
    }

    public function testItFlushesTheCacheWhenUpdatingARole(): void
    {
        $role = $this->app->make(Role::class)->create(['name' => 'new']);

        $role->name = 'other name';
        $role->save();

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheInitCount + $this->cacheLoadCount + $this->cacheRunCount);
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

        $this->assertQueryCount($this->cacheInitCount + $this->cacheLoadCount + $this->cacheRunCount);
    }

    public function testItFlushesTheCacheWhenAssigningAPermissionToARole(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->resetQueryCount();

        $this->registrar->getPermissions();

        $this->assertQueryCount($this->cacheInitCount + $this->cacheLoadCount + $this->cacheRunCount);
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

        $this->assertQueryCount($this->cacheInitCount + $this->cacheLoadCount + $this->cacheRunCount);
    }

    public function testNoOpPermissionSyncDoesNotFlushTheCache(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);
        $this->registrar->getPermissions();

        $this->testUserRole->syncPermissions($this->testUserPermission);

        $this->resetQueryCount();
        $this->registrar->getPermissions();

        $this->assertQueryCount(0);
    }

    public function testNoOpPermissionEffectSyncDoesNotFlushTheCache(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);
        $this->registrar->getPermissions();

        $this->testUserRole->syncPermissionEffects(allowed: [$this->testUserPermission]);

        $this->resetQueryCount();
        $this->registrar->getPermissions();

        $this->assertQueryCount(0);
    }

    public function testItUsesTheCacheForHasPermissionTo(): void
    {
        $this->testUserRole->givePermissionTo(['edit-articles', 'edit-news', 'Edit News']);
        $this->testUser->assignRole('testRole');
        $this->testUser->loadMissing('roles', 'permissions');

        // assignRole() loaded the catalog to find the role by name, so the first check is cached too.
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

    public function testColdAuthorizationLoadsTheCatalogAndTheModelAssignmentsOnce(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole('testRole');
        $this->registrar->forgetCachedPermissions();
        $this->resetQueryCount();

        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));

        // The catalog, the user's direct permissions and the user's roles are each filled once.
        // A database store also reads the assignment token.
        $this->assertQueryCount(
            $this->cacheRunCount + 2
            + 3 * ($this->cacheInitCount + $this->cacheLoadCount)
            + $this->cacheInitCount
        );
    }

    public function testItDifferentiatesTheCacheByGuardName(): void
    {
        // Upstream passes the guard name as a permission name, so its test throws on the
        // next line before reaching the guard check. Create that permission so it continues.
        $this->app->make(Permission::class)->create(['name' => 'web']);
        $this->testUserRole->givePermissionTo(['edit-articles', 'web']);
        $this->testUser->assignRole('testRole');
        $this->testUser->loadMissing('roles', 'permissions');

        // assignRole() loaded the catalog to find the role by name.
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

        // assignRole() loaded the catalog to find the role by name.
        $this->resetQueryCount();
        $this->registrar->getPermissions();
        $this->assertQueryCount(0);

        $this->resetQueryCount();
        $actual = $this->testUser->getAllPermissions()->pluck('name')->sort()->values();

        $this->assertEquals(collect($expected), $actual);
        $this->assertQueryCount(0);
    }

    public function testItShouldNotOverHydrateRolesForGetAllPermissions(): void
    {
        $this->testUserRole->givePermissionTo(['edit-articles', 'edit-news']);
        $permissions = $this->registrar->getPermissions();
        $roles = $permissions->flatMap->roles;

        // Each role carries its own role-permission pivot with the deny flag, so permissions
        // can't share upstream's single role instance. The cache stores role attributes once.
        $this->assertNotSame($roles[0], $roles[1]);
        $this->assertSame($roles[0]->getKey(), $roles[1]->getKey());
        $this->assertNotSame(
            $roles[0]->pivot->getAttribute($this->registrar->pivotPermission),
            $roles[1]->pivot->getAttribute($this->registrar->pivotPermission),
        );

        $cacheEntry = $this->registrar->getCacheRepository()->get($this->registrar->getCacheKey());

        $this->assertSame('present', $cacheEntry['__hypervel_model_cache']);

        $payload = $cacheEntry['value'];

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
        $this->assertQueryCount($this->cacheInitCount + $this->cacheLoadCount + $this->cacheRunCount);

        Artisan::call('permission:cache-reset');

        $this->resetQueryCount();
        $this->registrar->getPermissions();
        $this->assertQueryCount($this->cacheInitCount + $this->cacheLoadCount + $this->cacheRunCount);
    }

    public function testItShowsAnErrorWhenTheCacheExistsButCannotBeFlushed(): void
    {
        $registrar = m::mock(PermissionRegistrar::class)->makePartial();
        $registrar->cacheKey = $this->registrar->cacheKey;
        $registrar->shouldReceive('forgetCachedPermissions')->once()->andReturn(false);

        $cacheRepository = m::mock(Repository::class);
        $cacheRepository->shouldReceive('has')->with($registrar->cacheKey)->andReturn(true);
        $registrar->shouldReceive('getCacheRepository')->once()->andReturn($cacheRepository);

        $this->app->instance(PermissionRegistrar::class, $registrar);

        Artisan::call('permission:cache-reset');

        $this->assertStringContainsString('Unable to flush cache.', Artisan::output());
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
