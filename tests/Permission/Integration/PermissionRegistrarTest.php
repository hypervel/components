<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Closure;
use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Exceptions\PermissionAlreadyExists;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Exceptions\RoleDoesNotExist;
use Hypervel\Permission\Models\Permission as HypervelPermission;
use Hypervel\Permission\Models\Role as HypervelRole;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Permission\Fixtures\Models\Permission as TestPermission;
use Hypervel\Tests\Permission\Fixtures\Models\Role as TestRole;
use Hypervel\Tests\Permission\Fixtures\Models\Team;
use Hypervel\Tests\Permission\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use UnitEnum;
use WeakReference;

class PermissionRegistrarTest extends TestCase
{
    public function testItCanClearLoadedPermissionsCollection(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $registrar->getPermissions();

        $this->assertTrue(CoroutineContext::has(PermissionRegistrar::PERMISSION_CATALOG_CONTEXT_KEY));

        $registrar->clearPermissionsCollection();

        $this->assertFalse(CoroutineContext::has(PermissionRegistrar::PERMISSION_CATALOG_CONTEXT_KEY));
    }

    public function testItClearsTheLoadedPermissionsCollectionWhenReinitializingTheCache(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $registrar->getPermissions();

        $this->assertTrue(CoroutineContext::has(PermissionRegistrar::PERMISSION_CATALOG_CONTEXT_KEY));

        $registrar->initializeCache();

        $this->assertFalse(CoroutineContext::has(PermissionRegistrar::PERMISSION_CATALOG_CONTEXT_KEY));
    }

    public function testItDoesNotLeakAPreviousTenantsPermissionsAfterSwitchingCacheContextViaInitializeCache(): void
    {
        // Two separate cache "stores" stand in for two tenants' cache namespaces
        // (e.g. distinct cache prefixes/connections in a real multi-tenant app).
        config([
            'cache.stores.tenant_a' => ['driver' => 'array'],
            'cache.stores.tenant_b' => ['driver' => 'array'],
        ]);

        // Insert both tenants' rows via the query builder, bypassing Eloquent,
        // so the RefreshesPermissionCache model events don't auto-bust the cache and
        // mask the very staleness this test is meant to catch.
        $tenantAId = DB::table('permissions')->insertGetId([
            'name' => 'tenant-permission',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['permission.cache.store' => 'tenant_a']);
        app(PermissionRegistrar::class)->initializeCache();

        $loaded = app(PermissionRegistrar::class)->getPermissions()->firstWhere('name', 'tenant-permission');
        $this->assertSame($tenantAId, $loaded->getKey());

        // Simulate switching to tenant B: its own row for the "same" permission has
        // a different primary key, as it would in a separate tenant database.
        DB::table('permissions')->where('id', $tenantAId)->delete();
        $tenantBId = DB::table('permissions')->insertGetId([
            'name' => 'tenant-permission',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertNotSame($tenantAId, $tenantBId);

        config(['permission.cache.store' => 'tenant_b']);
        app(PermissionRegistrar::class)->initializeCache();

        $loaded = app(PermissionRegistrar::class)->getPermissions()->firstWhere('name', 'tenant-permission');
        $this->assertSame($tenantBId, $loaded->getKey());
    }

    public function testItPicksUpAReboundCacheManagerWhenInitializeCacheRunsAfterTheContainerCacheBindingIsReplaced(): void
    {
        // Neither the array nor the file store apply cache.prefix (only
        // database/redis/storage do), so this needs a store that
        // actually honours the prefix to observe the staleness.
        if (! Schema::hasTable('cache')) {
            $this->createCacheTable();
        }
        config()->set('cache.default', 'database');

        // This mirrors what spatie/laravel-multitenancy's PrefixCacheTask does on every
        // tenant switch: change cache.prefix, then forget the container's cache
        // singletons so they get rebuilt against the new prefix.
        $switchPrefix = function (string $prefix): void {
            config()->set('cache.prefix', $prefix);
            app('cache')->forgetDriver(config('cache.default'));
            app()->forgetInstance('cache');
            app()->forgetInstance('cache.store');
            Cache::clearResolvedInstances();
        };

        $switchPrefix('tenant_a_');
        app(PermissionRegistrar::class)->initializeCache();
        $this->assertSame('tenant_a_', app(PermissionRegistrar::class)->getCacheStore()->getPrefix());

        $switchPrefix('tenant_b_');
        app(PermissionRegistrar::class)->initializeCache();
        $this->assertSame('tenant_b_', app(PermissionRegistrar::class)->getCacheStore()->getPrefix());
    }

    public function testItCanCheckUids(): void
    {
        $uids = [
            // UUIDs
            '00000000-0000-0000-0000-000000000000',
            '9be37b52-e1fa-4e86-b65f-cbfcbedde838',
            'fc458041-fb21-4eea-a04b-b55c87a7224a',
            '78144b52-a889-11ed-afa1-0242ac120002',
            '78144f4e-a889-11ed-afa1-0242ac120002',
            // GUIDs
            '4b8590bb-90a2-4f38-8dc9-70e663a5b0e5',
            'A98C5A1E-A742-4808-96FA-6F409E799937',
            '1f01164a-98e9-4246-93ec-7941aefb1da6',
            '91b73d20-89e6-46b0-b39b-632706cc3ed7',
            '0df4a5b8-7c2e-484f-ad1d-787d1b83aacc',
            // ULIDs
            '01GRVB3DREB63KNN4G2QVV99DF',
            '01GRVB3DRECY317SJCJ6DMTFCA',
            '01GRVB3DREGGPBXNH1M24GX1DS',
            '01GRVB3DRESRM2K9AVQSW1JCKA',
            '01GRVB3DRES5CQ31PB24MP4CSV',
        ];

        $notUids = [
            '9be37b52-e1fa',
            '9be37b52-e1fa-4e86',
            '9be37b52-e1fa-4e86-b65f',
            '01GRVB3DREB63KNN4G2',
            'TEST STRING',
            '00-00-00-00-00-00',
            '91GRVB3DRES5CQ31PB24MP4CSV',
        ];

        foreach ($uids as $uid) {
            $this->assertTrue(PermissionRegistrar::isUid($uid));
        }

        foreach ($notUids as $notUid) {
            $this->assertFalse(PermissionRegistrar::isUid($notUid));
        }
    }

    public function testItCanGetPermissionClass(): void
    {
        $this->assertSame(HypervelPermission::class, $this->app->make(PermissionRegistrar::class)->getPermissionClass());
        $this->assertInstanceOf(HypervelPermission::class, $this->app->make(PermissionContract::class));
    }

    public function testItCanChangePermissionClass(): void
    {
        $this->assertSame(HypervelPermission::class, $this->app->make('config')->get('permission.models.permission'));
        $this->assertSame(HypervelPermission::class, $this->app->make(PermissionRegistrar::class)->getPermissionClass());
        $this->assertInstanceOf(HypervelPermission::class, $this->app->make(PermissionContract::class));

        $this->app->make(PermissionRegistrar::class)->setPermissionClass(TestPermission::class);

        $this->assertSame(TestPermission::class, $this->app->make('config')->get('permission.models.permission'));
        $this->assertSame(TestPermission::class, $this->app->make(PermissionRegistrar::class)->getPermissionClass());
        $this->assertInstanceOf(TestPermission::class, $this->app->make(PermissionContract::class));

        $this->app->make(PermissionRegistrar::class)->initializeCache();

        $this->assertSame(TestPermission::class, $this->app->make(PermissionRegistrar::class)->getPermissionClass());
        $this->assertInstanceOf(TestPermission::class, $this->app->make(PermissionContract::class));
    }

    public function testItCanGetRoleClass(): void
    {
        $this->assertSame(HypervelRole::class, $this->app->make(PermissionRegistrar::class)->getRoleClass());
        $this->assertInstanceOf(HypervelRole::class, $this->app->make(RoleContract::class));
    }

    public function testItCanChangeRoleClass(): void
    {
        $this->assertSame(HypervelRole::class, $this->app->make('config')->get('permission.models.role'));
        $this->assertSame(HypervelRole::class, $this->app->make(PermissionRegistrar::class)->getRoleClass());
        $this->assertInstanceOf(HypervelRole::class, $this->app->make(RoleContract::class));

        $this->app->make(PermissionRegistrar::class)->setRoleClass(TestRole::class);

        $this->assertSame(TestRole::class, $this->app->make('config')->get('permission.models.role'));
        $this->assertSame(TestRole::class, $this->app->make(PermissionRegistrar::class)->getRoleClass());
        $this->assertInstanceOf(TestRole::class, $this->app->make(RoleContract::class));

        $this->app->make(PermissionRegistrar::class)->initializeCache();

        $this->assertSame(TestRole::class, $this->app->make(PermissionRegistrar::class)->getRoleClass());
        $this->assertInstanceOf(TestRole::class, $this->app->make(RoleContract::class));
    }

    public function testItCanChangeTeamClass(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertNull($registrar->getTeamClass());

        $registrar->setTeamClass(Team::class);
        $registrar->initializeCache();

        $this->assertSame(Team::class, $this->app->make('config')->get('permission.models.team'));
        $this->assertSame(Team::class, $registrar->getTeamClass());

        $registrar->setTeamClass(null);
        $registrar->initializeCache();

        $this->assertNull($this->app->make('config')->get('permission.models.team'));
        $this->assertNull($registrar->getTeamClass());
    }

    public function testItCanChangeTeamId(): void
    {
        $teamId = '00000000-0000-0000-0000-000000000000';

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($teamId);

        $this->assertSame($teamId, $this->app->make(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function testItCanChangeTeamIdUsingAModelInstance(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $registrar->setPermissionsTeamId($this->testUser);

        $this->assertSame($this->testUser->getKey(), $registrar->getPermissionsTeamId());
    }

    public function testItRejectsAnUndefinedCacheStore(): void
    {
        // Upstream silently falls back to the array store, which hides the configuration
        // error and changes the selected backend.
        config()->set('permission.cache.store', 'this-store-does-not-exist');

        app(PermissionRegistrar::class)->initializeCache();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Cache store [this-store-does-not-exist] is not defined.');

        app(PermissionRegistrar::class)->getCacheStore();
    }

    // REMOVED: upstream's "retries loading permissions when another load is already in progress".
    // The loaded catalog is coroutine-local, so there is no shared in-progress load to wait for.

    public function testPermissionLookupUsesGuardExactCatalogIndex(): void
    {
        $permissionClass = $this->app->make(PermissionContract::class);
        $webPermission = $permissionClass::findByName('edit-articles', 'web');
        $apiPermission = $permissionClass::create(['name' => 'edit-articles', 'guard_name' => 'api']);
        $foundApiPermission = $permissionClass::findByName('edit-articles', 'api');
        $foundApiPermissionById = $permissionClass::findById($apiPermission->getKey(), 'api');

        $this->assertSame($webPermission->getKey(), $permissionClass::findByName('edit-articles', 'web')->getKey());
        $this->assertSame($apiPermission->getKey(), $foundApiPermission->getKey());
        $this->assertSame('api', $foundApiPermission->guard_name);
        $this->assertSame($apiPermission->getKey(), $foundApiPermissionById->getKey());
        $this->assertSame('api', $foundApiPermissionById->guard_name);
    }

    public function testPermissionCatalogIdLookupPreservesCatalogOrder(): void
    {
        $permissionClass = $this->app->make(PermissionContract::class);
        $second = $permissionClass::findByName('edit-news');
        $third = $permissionClass::findByName('edit-blog');
        $registrar = $this->app->make(PermissionRegistrar::class);

        $permissions = $registrar->getPermissions([
            $second->getKeyName() => [$third->getKey(), $second->getKey()],
            'guard_name' => 'web',
        ]);

        $this->assertSame(
            [$second->getKey(), $third->getKey()],
            $permissions->pluck($second->getKeyName())->all(),
        );
    }

    public function testMissingCatalogLookupReturnsEmptyCollectionAndModelsStillThrow(): void
    {
        $permissionClass = $this->app->make(PermissionContract::class);
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertTrue($registrar->getPermissions(['name' => 'missing-permission', 'guard_name' => 'web'])->isEmpty());

        $this->expectException(PermissionDoesNotExist::class);

        $permissionClass::findByName('missing-permission');
    }

    public function testRoleLookupUsesCatalogIndexAndStillThrowsWhenMissing(): void
    {
        $roleClass = $this->app->make(RoleContract::class);
        $role = $roleClass::findByName('testRole');

        $this->assertTrue($role->is($roleClass::findById($role->getKey())));

        $this->expectException(RoleDoesNotExist::class);

        $roleClass::findByName('missing-role');
    }

    public function testPermissionCreateUsesDatabaseForDuplicateCheckWhenCatalogIsStale(): void
    {
        $permissionClass = $this->app->make(PermissionContract::class);

        $this->app->make(PermissionRegistrar::class)->getPermissions();

        $permissionClass::query()->insert([
            'name' => 'stale-catalog-permission',
            'guard_name' => 'web',
        ]);

        $this->expectException(PermissionAlreadyExists::class);

        $permissionClass::create(['name' => 'stale-catalog-permission']);
    }

    public function testPermissionFindOrCreateUsesDatabaseWhenCatalogIsStale(): void
    {
        $permissionClass = $this->app->make(PermissionContract::class);

        $this->app->make(PermissionRegistrar::class)->getPermissions();

        $id = $permissionClass::query()->insertGetId([
            'name' => 'stale-catalog-find-or-create',
            'guard_name' => 'web',
        ]);

        $permission = $permissionClass::findOrCreate('stale-catalog-find-or-create');

        $this->assertSame($id, $permission->getKey());
        $this->assertSame('stale-catalog-find-or-create', $permission->name);
        $this->assertSame('web', $permission->guard_name);
    }

    public function testCatalogReportsWhetherItContainsDeniedRolePermissions(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertFalse($registrar->hasDeniedRolePermissions());

        $this->testUserRole->denyPermissionTo($this->testUserPermission);

        $this->assertTrue($registrar->hasDeniedRolePermissions());
    }

    public function testCatalogResolvedRolesExposeExpectedAttributes(): void
    {
        $role = $this->app->make(RoleContract::class)::findByName('testRole');

        $this->assertSame($this->testUserRole->getKey(), $role->getKey());
        $this->assertSame('testRole', $role->name);
        $this->assertSame('web', $role->guard_name);
        $this->assertFalse(array_key_exists('created_at', $role->getAttributes()));
    }

    #[DataProvider('catalogRoleConnections')]
    public function testCatalogModelsMatchDatabaseLoadedModels(string $roleClass, string $connection): void
    {
        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-articles');
        $this->app->make(PermissionRegistrar::class)->setRoleClass($roleClass);
        $user = $this->testUser->fresh();

        $role = $roleClass::findByName('testRole');
        $permission = $this->app->make(PermissionContract::class)::findByName('edit-articles');

        $this->assertSame($connection, $role->getConnectionName());
        $this->assertTrue($role->is($roleClass::query()->find($role->getKey())));
        $this->assertTrue($user->roles->contains($role));
        $this->assertTrue($user->permissions->contains($permission));
    }

    /**
     * Provide catalog role connections and their hydrated names.
     */
    public static function catalogRoleConnections(): array
    {
        return [
            'default connection' => [HypervelRole::class, 'testing'],
            'empty connection' => [EmptyConnectionRole::class, 'testing'],
            'read alias' => [ReadConnectionRole::class, 'testing'],
            'write alias' => [WriteConnectionRole::class, 'testing::write'],
        ];
    }

    public function testInitializeCacheUsesOptionalConfigurationDefaults(): void
    {
        $permissionConfig = config()->array('permission');
        unset(
            $permissionConfig['models']['team'],
            $permissionConfig['models']['default_model'],
            $permissionConfig['cache']['expiration_seconds'],
            $permissionConfig['cache']['store'],
            $permissionConfig['cache']['column_names_except'],
        );
        config()->set('permission', $permissionConfig);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->initializeCache();

        $registrar->setPermissionsTeamId('team-a');

        $this->assertSame(HypervelRole::class, $registrar->getRoleClass());
        $this->assertSame(HypervelPermission::class, $registrar->getPermissionClass());
        $this->assertNull($registrar->getTeamClass());
        $this->assertNull(Config::defaultModel());
        $this->assertSame('team-a', $registrar->getPermissionsTeamId());
        $this->assertSame(PermissionRegistrar::DEFAULT_CACHE_EXPIRATION_SECONDS, $registrar->cacheExpirationTime);
        $this->assertSame($this->app->make('cache')->store()->getStore(), $registrar->getCacheStore());

        $role = $this->app->make(RoleContract::class)::findByName('testRole');

        $this->assertFalse(array_key_exists('created_at', $role->getAttributes()));
    }

    public function testNullPivotKeysUseConventionalColumnNames(): void
    {
        config([
            'permission.column_names.role_pivot_key' => null,
            'permission.column_names.permission_pivot_key' => null,
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->initializeCache();

        $this->assertSame(PermissionRegistrar::DEFAULT_ROLE_PIVOT_KEY, $registrar->pivotRole);
        $this->assertSame(PermissionRegistrar::DEFAULT_PERMISSION_PIVOT_KEY, $registrar->pivotPermission);
    }

    public function testInitializeCacheUsesOptionalSchemaAndCacheKeyDefaults(): void
    {
        $permissionConfig = config()->array('permission');
        unset(
            $permissionConfig['column_names']['role_pivot_key'],
            $permissionConfig['column_names']['permission_pivot_key'],
            $permissionConfig['column_names']['team_foreign_key'],
            $permissionConfig['cache']['keys']['roles'],
            $permissionConfig['cache']['keys']['model_roles'],
            $permissionConfig['cache']['keys']['model_permissions'],
            $permissionConfig['cache']['keys']['model_token'],
        );
        config()->set('permission', $permissionConfig);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->initializeCache();

        $this->assertSame(PermissionRegistrar::DEFAULT_ROLE_PIVOT_KEY, $registrar->pivotRole);
        $this->assertSame(PermissionRegistrar::DEFAULT_PERMISSION_PIVOT_KEY, $registrar->pivotPermission);
        $this->assertSame(PermissionRegistrar::DEFAULT_TEAM_FOREIGN_KEY, $registrar->teamsKey);
        $this->assertSame(PermissionRegistrar::ROLE_CATALOG_CACHE_KEY, $registrar->getCacheKey());
    }

    public function testInitializeCacheRejectsRequiredDefaultModelColumns(): void
    {
        $this->app->make('config')->set(
            'permission.cache.column_names_except',
            ['id', 'name', 'guard_name'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Permission cache column exclusions cannot contain required role columns [id, name, guard_name] '
            . 'or permission columns [id, name, guard_name].'
        );

        $this->app->make(PermissionRegistrar::class)->initializeCache();
    }

    public function testInitializeCacheUsesConfiguredModelPrimaryKeys(): void
    {
        $this->app->make('config')->set([
            'permission.models.role' => TestRole::class,
            'permission.models.permission' => TestPermission::class,
            'permission.cache.column_names_except' => ['role_test_id', 'permission_test_id'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Permission cache column exclusions cannot contain required role columns [role_test_id] '
            . 'or permission columns [permission_test_id].'
        );

        $this->app->make(PermissionRegistrar::class)->initializeCache();
    }

    public function testInitializeCacheRejectsTheTeamColumnOnlyForRoles(): void
    {
        $this->app->make('config')->set([
            'permission.teams' => true,
            'permission.cache.column_names_except' => ['team_test_id'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('role columns [team_test_id]');

        $this->app->make(PermissionRegistrar::class)->initializeCache();
    }

    public function testInitializeCacheValidatesThePartitionColumnWithoutResolvingIt(): void
    {
        $resolverCalled = false;

        PermissionRegistrar::flushState();
        $this->app->forgetInstance(PermissionRegistrar::class);
        PermissionRegistrar::resolvePartitionUsing('workspace_id', function () use (&$resolverCalled): string {
            $resolverCalled = true;

            return 'workspace-a';
        });
        $this->app->make('config')->set('permission.cache.column_names_except', ['workspace_id']);

        try {
            $this->app->make(PermissionRegistrar::class);

            $this->fail('The required partition column was accepted as a cache exclusion.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('role columns [workspace_id]', $exception->getMessage());
            $this->assertStringContainsString('permission columns [workspace_id]', $exception->getMessage());
        }

        $this->assertFalse($resolverCalled);
    }

    public function testItResolvesSingleRecordLookupsByNameAndByKeyFromTheIndex(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        foreach ($registrar->getPermissions() as $permission) {
            $byName = $registrar->getPermissions(['name' => $permission->name, 'guard_name' => $permission->guard_name], true);
            $byKey = $registrar->getPermissions([$permission->getKeyName() => $permission->getKey(), 'guard_name' => $permission->guard_name], true);

            $this->assertCount(1, $byName);
            $this->assertSame($permission, $byName->first());
            $this->assertCount(1, $byKey);
            $this->assertSame($permission, $byKey->first());
        }
    }

    public function testItReturnsTheSamePermissionInstanceForIndexedAndFilteredLookups(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $params = ['name' => 'edit-articles', 'guard_name' => 'web'];

        $this->assertSame($registrar->getPermissions($params)->first(), $registrar->getPermissions($params, true)->first());
    }

    public function testItDoesNotResolveAnIndexedLookupAcrossGuards(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertEmpty($registrar->getPermissions(['name' => 'edit-articles', 'guard_name' => 'admin'], true));
        $this->assertEmpty($registrar->getPermissions(['name' => 'admin-permission', 'guard_name' => 'web'], true));
        $this->assertCount(1, $registrar->getPermissions(['name' => 'admin-permission', 'guard_name' => 'admin'], true));
    }

    public function testItStillFiltersLookupsThatAreNotIndexable(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertSame(
            $registrar->getPermissions()->where('guard_name', 'web')->count(),
            $registrar->getPermissions(['guard_name' => 'web'])->count(),
        );
        $this->assertCount(1, $registrar->getPermissions(['guard_name' => 'web'], true));
        $this->assertCount(1, $registrar->getPermissions(['name' => 'edit-articles'], true));
        $this->assertEmpty($registrar->getPermissions(['name' => 'edit-articles', 'guard_name' => 'web', 'id' => 0], true));
    }

    public function testItRebuildsTheLookupIndexWhenThePermissionCacheIsFlushed(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        try {
            HypervelPermission::findByName('late-permission');
            $this->fail('The missing permission was found.');
        } catch (PermissionDoesNotExist) {
        }

        $created = HypervelPermission::create(['name' => 'late-permission']);

        $this->assertSame($created->getKey(), HypervelPermission::findByName('late-permission')->getKey());
        $this->assertSame('late-permission', HypervelPermission::findById($created->getKey())->name);

        $registrar->clearPermissionsCollection();

        $this->assertSame($created->getKey(), HypervelPermission::findByName('late-permission')->getKey());
    }

    #[DataProvider('numericLookupProvider')]
    public function testItKeepsExactMatchingForNumericLookupValues(
        string $attribute,
        mixed $value,
        ?string $expectedName,
        ?string $expectedGuard,
    ): void {
        DB::table('permissions')->insert([
            ['name' => '01', 'guard_name' => 'web'],
            ['name' => '1', 'guard_name' => 'web'],
            ['name' => 'numeric-guard', 'guard_name' => '01'],
            ['name' => 'numeric-guard', 'guard_name' => '1'],
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // Each lookup runs once through the index and once through a shape only the filter handles.
        [$indexed, $filtered] = match ($attribute) {
            'name' => [['name' => $value, 'guard_name' => 'web'], ['name' => $value]],
            'guard_name' => [['name' => 'numeric-guard', 'guard_name' => $value], ['guard_name' => $value]],
            'id' => [['id' => $value, 'guard_name' => 'web'], ['id' => $value, 'name' => 'edit-articles']],
        };

        $permission = $registrar->getPermissions($indexed, true)->first();

        $this->assertSame($registrar->getPermissions($filtered, true)->first(), $permission);
        $this->assertSame($expectedName, $permission?->name);

        if ($expectedGuard !== null) {
            $this->assertSame($expectedGuard, $permission?->guard_name);
        }
    }

    /**
     * Provide numeric-looking lookup values and the permission each must match exactly.
     */
    public static function numericLookupProvider(): array
    {
        return [
            'name string' => ['name', '1', '1', null],
            'name with a leading zero' => ['name', '01', '01', null],
            'name integer' => ['name', 1, '1', null],
            'name exponent' => ['name', '1e0', null, null],
            'name boolean' => ['name', true, null, null],
            'guard string' => ['guard_name', '1', 'numeric-guard', '1'],
            'guard with a leading zero' => ['guard_name', '01', 'numeric-guard', '01'],
            'guard integer' => ['guard_name', 1, 'numeric-guard', '1'],
            'guard boolean' => ['guard_name', true, null, null],
            'key integer' => ['id', 1, 'edit-articles', null],
            'key string' => ['id', '1', 'edit-articles', null],
            'key with a leading zero' => ['id', '01', null, null],
            'key exponent' => ['id', '1e0', null, null],
            'key decimal string' => ['id', '1.0', null, null],
            'key with a sign' => ['id', '+1', null, null],
            'key with spaces' => ['id', ' 1 ', null, null],
            'key boolean' => ['id', true, null, null],
            'key float' => ['id', 1.0, null, null],
        ];
    }

    // REMOVED: upstream's unsaved returned-model mutation test; callers save changed roles and permissions before further lookups, which keeps indexed lookups free of rescans.

    // REMOVED: upstream's runtime boolean cast lookup test; its name lookup only matched through a loose `==` comparison of true with the requested name.

    public function testItUsesCurrentCustomAccessorsWithoutEagerlyEvaluatingUnrelatedAttributes(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionClass(LookupPermission::class)->forgetCachedPermissions();
        config(['testing.permission_prefix' => 'first-']);

        $permission = LookupPermission::findByName('first-edit-articles');

        config(['testing.permission_prefix' => 'second-']);

        $this->assertSame($permission, LookupPermission::findByName('second-edit-articles'));
        $this->assertEmpty($registrar->getPermissions(['name' => 'first-edit-articles', 'guard_name' => 'web'], true));

        $registrar->clearPermissionsCollection();
        config(['testing.permission_prefix' => null]);

        $this->assertCount(1, $registrar->getPermissions(['guard_name' => 'web'], true));
    }

    public function testItUsesCurrentCustomKeyAccessorsForKeyLookups(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionClass(KeyLookupPermission::class)->forgetCachedPermissions();
        config(['testing.permission_key_offset' => 1000]);

        $permission = KeyLookupPermission::findByName('edit-articles');
        $storedKey = $this->testUserPermission->getKey();

        $this->assertSame($storedKey + 1000, $permission->getKey());

        config(['testing.permission_key_offset' => 2000]);

        $this->assertSame($permission, KeyLookupPermission::findById($storedKey + 2000));
        $this->assertSame($permission, $registrar->getPermissions(['id' => $storedKey + 2000, 'name' => 'edit-articles'], true)->first());
        $this->assertEmpty($registrar->getPermissions(['id' => $storedKey + 1000, 'guard_name' => 'web'], true));
    }

    public function testItSupportsCustomPermissionKeysAcrossCacheHydration(): void
    {
        $this->setUpCustomModels();
        $registrar = $this->app->make(PermissionRegistrar::class);

        $permission = TestPermission::findByName('edit-articles');

        $this->assertSame($permission, TestPermission::findById($permission->getKey()));
        $this->assertEmpty($registrar->getPermissions(['permission_test_id' => $permission->getKey(), 'guard_name' => 'admin'], true));

        $registrar->clearPermissionsCollection();

        $this->assertSame('edit-articles', TestPermission::findById($permission->getKey())->name);
    }

    #[DataProvider('keyedCollectionProvider')]
    public function testItReturnsTheFirstMatchingModelFromCustomCollections(bool $keyed): void
    {
        config(['testing.keyed_permissions' => $keyed]);
        $registrar = new class($this->app->make(ConfigRepository::class), $this->app, $this->app->make(ModelCacheCoordinator::class)) extends PermissionRegistrar {
            /**
             * Get permissions with their roles, giving the second the first one's name.
             */
            protected function getPermissionsWithRoles(?string $permissionClass = null): Collection
            {
                $permissions = parent::getPermissionsWithRoles($permissionClass);
                $permissions[1]->name = $permissions[0]->name;

                return new LookupPermissionCollection(config('testing.keyed_permissions')
                    ? $permissions->keyBy('id')->all()
                    : $permissions->all());
            }
        };
        $registrar->forgetCachedPermissions();

        $permissions = $registrar->getPermissions(['name' => 'edit-articles', 'guard_name' => 'web'], true);

        $this->assertInstanceOf(Collection::class, $permissions);
        $this->assertCount(1, $permissions);
        $this->assertSame($this->testUserPermission->getKey(), $permissions->first()->getKey());
        $this->assertCount(2, $registrar->getPermissions(['name' => 'edit-articles']));
    }

    /**
     * Provide whether the custom permission collection is keyed by permission ID.
     */
    public static function keyedCollectionProvider(): array
    {
        return [
            'keyed' => [true],
            'list' => [false],
        ];
    }

    // REMOVED: upstream's excluded-primary-key lookup test; required model columns cannot be excluded from the cache (see testInitializeCacheRejectsRequiredDefaultModelColumns).

    public function testItInvalidatesLookupsAfterSavedNameAndGuardChangesAndDeletion(): void
    {
        $permission = HypervelPermission::findByName('edit-articles');
        $id = $permission->getKey();
        $permission->update(['name' => 'renamed', 'guard_name' => 'admin']);

        $this->assertPermissionLookupFails(fn () => HypervelPermission::findByName('edit-articles'));
        $this->assertPermissionLookupFails(fn () => HypervelPermission::findById($id, 'web'));
        $this->assertSame($id, HypervelPermission::findByName('renamed', 'admin')->getKey());

        HypervelPermission::findById($id, 'admin')->delete();

        $this->assertPermissionLookupFails(fn () => HypervelPermission::findByName('renamed', 'admin'));
        $this->assertPermissionLookupFails(fn () => HypervelPermission::findById($id, 'admin'));
    }

    #[DataProvider('inMemoryCacheClearingProvider')]
    public function testItReleasesIndexedModelsWhenClearingTheInMemoryCache(string $method): void
    {
        $permission = HypervelPermission::findByName('edit-articles');
        $reference = WeakReference::create($permission);
        unset($permission);

        $this->app->make(PermissionRegistrar::class)->{$method}();
        gc_collect_cycles();

        $this->assertNull($reference->get());
        $this->assertSame('edit-articles', HypervelPermission::findByName('edit-articles')->name);
    }

    /**
     * Provide the methods that clear the in-memory permission catalog.
     */
    public static function inMemoryCacheClearingProvider(): array
    {
        return [
            'clearPermissionsCollection' => ['clearPermissionsCollection'],
            'forgetCachedPermissions' => ['forgetCachedPermissions'],
            'initializeCache' => ['initializeCache'],
        ];
    }

    /**
     * Assert that the permission lookup throws a missing permission exception.
     */
    private function assertPermissionLookupFails(Closure $lookup): void
    {
        try {
            $lookup();
            $this->fail('The permission lookup succeeded.');
        } catch (PermissionDoesNotExist) {
            $this->addToAssertionCount(1);
        }
    }
}

class LookupPermissionCollection extends Collection
{
}

class LookupPermission extends HypervelPermission
{
    /**
     * Get the permission name with the configured prefix.
     */
    public function getNameAttribute(string $value): string
    {
        $prefix = config('testing.permission_prefix');

        if ($prefix === null) {
            throw new LogicException('The name accessor should not be evaluated.');
        }

        return $prefix . $value;
    }

    /**
     * Create a new permission collection.
     */
    public function newCollection(array $models = []): LookupPermissionCollection
    {
        return new LookupPermissionCollection($models);
    }
}

class KeyLookupPermission extends HypervelPermission
{
    /**
     * Get the permission key with the configured offset.
     */
    public function getIdAttribute(int $value): int
    {
        return $value + config('testing.permission_key_offset');
    }
}

class EmptyConnectionRole extends HypervelRole
{
    protected UnitEnum|string|null $connection = '';
}

class ReadConnectionRole extends HypervelRole
{
    protected UnitEnum|string|null $connection = 'testing::read';
}

class WriteConnectionRole extends HypervelRole
{
    protected UnitEnum|string|null $connection = 'testing::write';
}
