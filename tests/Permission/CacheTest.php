<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;

use function Hypervel\Coroutine\parallel;

class CacheTest extends TestCase
{
    public function testGlobalPermissionCacheStoresRolePivotsWithoutDuplicatingRoleAttributes(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->testUser->hasPermissionTo('edit-articles');

        $payload = $registrar->getCacheRepository()->get($registrar->cacheKey);

        $this->assertIsArray($payload);

        $permission = collect($payload['permissions'])->first(
            fn (array $permission): bool => $permission['attributes']['name'] === 'edit-articles',
        );

        $this->assertIsArray($permission);
        $this->assertArrayNotHasKey('attributes', $permission['roles'][0]);
        $this->assertSame($this->testUserRole->getKey(), $permission['roles'][0]['pivot'][$registrar->pivotRole]);
        $this->assertFalse($permission['roles'][0]['pivot']['is_forbidden']);
    }

    public function testRoleForbiddenPivotHydratesFromGlobalCache(): void
    {
        $this->testUser->assignRole('testRole');
        $this->testUserRole->giveForbiddenTo('edit-articles');

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));

        $this->app->make(PermissionRegistrar::class)->clearPermissionsCollection();

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasForbiddenPermissionViaRoles('edit-articles'));
    }

    public function testPermissionCacheResetBumpsModelAssignmentCacheVersion(): void
    {
        $this->testUser->assignRole('testRole');
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertTrue($this->testUser->hasRole('testRole'));

        $firstVersion = $registrar->modelAssignmentCacheVersion();

        $registrar->forgetCachedPermissions();

        $this->assertGreaterThan($firstVersion, $registrar->modelAssignmentCacheVersion());
        $this->assertTrue($this->testUser->hasRole('testRole'));
    }

    public function testGlobalCatalogIsNotHeldOnWorkerSingletonAfterSharedCacheIsForgotten(): void
    {
        $this->testUser->assignRole('testRole');
        $permission = $this->app->make(PermissionContract::class)::create(['name' => 'publish-articles']);
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertFalse($this->testUser->hasPermissionTo('publish-articles'));

        $this->testUserRole->getConnection()
            ->table(Config::roleHasPermissionsTable())
            ->insert([
                $registrar->pivotPermission => $permission->getKey(),
                $registrar->pivotRole => $this->testUserRole->getKey(),
                'is_forbidden' => false,
            ]);

        $registrar->getCacheRepository()->forget($registrar->cacheKey);

        $results = parallel([
            fn (): bool => $this->testUser->hasPermissionTo('publish-articles'),
        ]);

        $this->assertTrue($results[0]);
    }

    public function testDeletingRoleCleansRolePermissionPivotWithoutForeignKeyCascades(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->assertSame(1, $this->testUserRole->getConnection()->table(Config::roleHasPermissionsTable())->count());

        $this->testUserRole->delete();

        $this->assertSame(0, $this->testUserRole->getConnection()->table(Config::roleHasPermissionsTable())->count());
    }

    public function testDeletingPermissionCleansRolePermissionPivotWithoutForeignKeyCascades(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->assertSame(1, $this->testUserRole->getConnection()->table(Config::roleHasPermissionsTable())->count());

        $this->testUserPermission->delete();

        $this->assertSame(0, $this->testUserRole->getConnection()->table(Config::roleHasPermissionsTable())->count());
    }
}
