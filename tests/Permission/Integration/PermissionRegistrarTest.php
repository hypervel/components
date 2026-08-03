<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Context\CoroutineContext;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Exceptions\PermissionAlreadyExists;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Exceptions\RoleDoesNotExist;
use Hypervel\Permission\Models\Permission as HypervelPermission;
use Hypervel\Permission\Models\Role as HypervelRole;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Tests\Permission\Fixtures\Models\Permission as TestPermission;
use Hypervel\Tests\Permission\Fixtures\Models\Role as TestRole;
use Hypervel\Tests\Permission\TestCase;
use InvalidArgumentException;

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

        $this->assertSame(HypervelPermission::class, $this->app->make('config')->get('permission.models.permission'));
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

        $this->assertSame(HypervelRole::class, $this->app->make('config')->get('permission.models.role'));
        $this->assertSame(TestRole::class, $this->app->make(PermissionRegistrar::class)->getRoleClass());
        $this->assertInstanceOf(TestRole::class, $this->app->make(RoleContract::class));
    }

    public function testItCanChangeTeamId(): void
    {
        $teamId = '00000000-0000-0000-0000-000000000000';

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($teamId);

        $this->assertSame($teamId, $this->app->make(PermissionRegistrar::class)->getPermissionsTeamId());
    }

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

        try {
            $permissionClass::findByName('missing-permission');
            $this->fail('Expected missing permission exception was not thrown.');
        } catch (PermissionDoesNotExist) {
            $this->assertTrue(true);
        }
    }

    public function testRoleLookupUsesCatalogIndexAndStillThrowsWhenMissing(): void
    {
        $roleClass = $this->app->make(RoleContract::class);
        $role = $roleClass::findByName('testRole');

        $this->assertTrue($role->is($roleClass::findById($role->getKey())));

        try {
            $roleClass::findByName('missing-role');
            $this->fail('Expected missing role exception was not thrown.');
        } catch (RoleDoesNotExist) {
            $this->assertTrue(true);
        }
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

    public function testInitializeCacheAcceptsDefaultColumnExclusions(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $registrar->initializeCache();

        $this->assertSame(HypervelRole::class, $registrar->getRoleClass());
        $this->assertSame(HypervelPermission::class, $registrar->getPermissionClass());
    }

    public function testInitializeCacheRejectsRequiredDefaultModelColumns(): void
    {
        $this->app->make('config')->set(
            'permission.cache.column_names_except',
            ['id', 'name', 'guard_name'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
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
        $this->expectExceptionMessage(
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
        $this->expectExceptionMessage('role columns [team_test_id]');

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
}
