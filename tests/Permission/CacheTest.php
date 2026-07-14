<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Context\CoroutineContext;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Tests\Permission\Fixtures\Models\User;

use function Hypervel\Coroutine\parallel;

class CacheTest extends TestCase
{
    public function testGlobalPermissionCacheStoresRolePivotsWithoutDuplicatingRoleAttributes(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->testUser->hasPermissionTo('edit-articles');

        $payload = $registrar->getCacheRepository()->get($registrar->getCacheKey());

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

    public function testPermissionCacheResetChangesModelAssignmentCacheToken(): void
    {
        $this->testUser->assignRole('testRole');
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertTrue($this->testUser->hasRole('testRole'));

        $firstToken = $registrar->modelAssignmentCacheToken();

        $registrar->forgetCachedPermissions();

        $this->assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $firstToken);
        $this->assertNotSame($firstToken, $registrar->modelAssignmentCacheToken());
        $this->assertTrue($this->testUser->hasRole('testRole'));
    }

    public function testRoleAssignmentMutationsInvalidateWarmModelRoleCache(): void
    {
        $this->assertFalse($this->testUser->hasRole('testRole'));

        $this->testUser->assignRole('testRole');
        $this->assertTrue($this->testUser->hasRole('testRole'));

        $this->testUser->removeRole('testRole');
        $this->assertFalse($this->testUser->hasRole('testRole'));

        $this->testUser->syncRoles('testRole2');
        $this->assertFalse($this->testUser->hasRole('testRole'));
        $this->assertTrue($this->testUser->hasRole('testRole2'));
    }

    public function testRoleAssignmentMutationsInvalidateWarmViaRolePermissionMemo(): void
    {
        $secondRole = $this->app->make(RoleContract::class)::findByName('testRole2');

        $this->testUserRole->givePermissionTo('edit-articles');
        $secondRole->givePermissionTo('edit-news');
        $this->testUser->assignRole($this->testUserRole);

        $this->assertSame(['edit-articles'], $this->testUser->getPermissionsViaRoles()->pluck('name')->all());

        $this->testUser->syncRoles($secondRole);

        $this->assertSame(['edit-news'], $this->testUser->getPermissionsViaRoles()->pluck('name')->all());
    }

    public function testUnsavedModelsDoNotUseViaRolePermissionMemo(): void
    {
        $user = new User(['email' => 'unsaved@user.com']);

        $this->assertSame([], $user->getPermissionsViaRoles()->all());
        $this->assertSame([], CoroutineContext::get(PermissionRegistrar::MODEL_VIA_ROLE_PERMISSIONS_CONTEXT_KEY, []));
    }

    public function testDirectPermissionMutationsInvalidateWarmModelPermissionCache(): void
    {
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));

        $this->testUser->givePermissionTo('edit-articles');
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));

        $this->testUser->revokePermissionTo('edit-articles');
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
    }

    public function testSyncPermissionsWithForbiddenInvalidatesWarmModelPermissionCache(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->hasForbiddenPermission('edit-articles'));

        $this->testUser->syncPermissionsWithForbidden(
            allowed: ['edit-news'],
            forbidden: ['edit-articles'],
        );

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-news'));
    }

    public function testRolePermissionMutationsInvalidateWarmGlobalPermissionCatalog(): void
    {
        $this->testUser->assignRole('testRole');
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));

        $this->testUserRole->givePermissionTo('edit-articles');
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));

        $this->testUserRole->revokePermissionTo('edit-articles');
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
    }

    public function testRolePermissionMutationsInvalidateWarmViaRolePermissionMemo(): void
    {
        $this->testUser->assignRole('testRole');

        $this->assertSame([], $this->testUser->getAllPermissions()->pluck('name')->all());

        $this->testUserRole->givePermissionTo('edit-articles');

        $this->assertSame(['edit-articles'], $this->testUser->getAllPermissions()->pluck('name')->all());
    }

    public function testRoleForbiddenPermissionMutationsInvalidateWarmGlobalPermissionCatalog(): void
    {
        $this->testUser->assignRole('testRole');
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->hasForbiddenPermissionViaRoles('edit-articles'));

        $this->testUserRole->syncPermissionsWithForbidden(forbidden: ['edit-articles']);

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasForbiddenPermissionViaRoles('edit-articles'));
    }

    public function testWildcardPermissionMutationsInvalidateWarmWildcardIndex(): void
    {
        $this->app->make('config')->set('permission.enable_wildcard_permission', true);
        $this->flushPermissionState();
        $this->app->make(PermissionContract::class)::create(['name' => 'posts.*']);

        $this->assertFalse($this->testUser->hasPermissionTo('posts.create'));

        $this->testUser->givePermissionTo('posts.*');
        $this->assertTrue($this->testUser->hasPermissionTo('posts.create'));

        $this->testUser->revokePermissionTo('posts.*');
        $this->assertFalse($this->testUser->hasPermissionTo('posts.create'));
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

        $registrar->getCacheRepository()->forget($registrar->getCacheKey());

        $results = parallel([
            fn (): bool => $this->testUser->hasPermissionTo('publish-articles'),
        ]);

        $this->assertTrue($results[0]);
    }

    public function testWildcardIndexKeyIsWellFormedWithoutPartitioning(): void
    {
        $this->app->make('config')->set('permission.enable_wildcard_permission', true);
        $this->flushPermissionState();

        $this->app->make(PermissionContract::class)::create(['name' => 'posts.*']);
        $this->testUser->givePermissionTo('posts.*');
        $registrar = $this->app->make(PermissionRegistrar::class);

        $registrar->getWildcardPermissionIndex($this->testUser);

        $keys = array_keys(CoroutineContext::get(PermissionRegistrar::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, []));

        $this->assertNotEmpty($keys);
        $this->assertFalse(str_starts_with($keys[0], ':'));
    }

    public function testDeletingPlainModelDoesNotBumpGlobalAssignmentCacheToken(): void
    {
        $anotherUser = User::create(['email' => 'another@user.com']);
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole('testRole');
        $anotherUser->assignRole('testRole');
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($anotherUser->hasPermissionTo('edit-articles'));

        $token = $registrar->modelAssignmentCacheToken();

        $this->testUser->delete();

        $this->assertSame($token, $registrar->modelAssignmentCacheToken());
        $this->assertTrue($anotherUser->hasPermissionTo('edit-articles'));
    }

    public function testDeletingRoleCleansRolePermissionPivotWithoutForeignKeyCascades(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $registrar = $this->app->make(PermissionRegistrar::class);
        $token = $registrar->modelAssignmentCacheToken();

        $this->assertSame(1, $this->testUserRole->getConnection()->table(Config::roleHasPermissionsTable())->count());

        $this->testUserRole->delete();

        $this->assertSame(0, $this->testUserRole->getConnection()->table(Config::roleHasPermissionsTable())->count());
        $this->assertNotSame($token, $registrar->modelAssignmentCacheToken());
    }

    public function testDeletingPermissionCleansRolePermissionPivotWithoutForeignKeyCascades(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $registrar = $this->app->make(PermissionRegistrar::class);
        $token = $registrar->modelAssignmentCacheToken();

        $this->assertSame(1, $this->testUserRole->getConnection()->table(Config::roleHasPermissionsTable())->count());

        $this->testUserPermission->delete();

        $this->assertSame(0, $this->testUserRole->getConnection()->table(Config::roleHasPermissionsTable())->count());
        $this->assertNotSame($token, $registrar->modelAssignmentCacheToken());
    }
}
