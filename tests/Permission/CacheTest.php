<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class CacheTest extends TestCase
{
    public function testGlobalPermissionCacheStoresRoleKeysWithoutDuplicatingRoleAttributes(): void
    {
        $deniedRole = $this->app->make(RoleContract::class)::findByName('testRole2');
        $this->testUserRole->givePermissionTo('edit-articles');
        $deniedRole->denyPermissionTo('edit-articles');
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->testUser->hasPermissionTo('edit-articles');

        $entry = $registrar->getCacheRepository()->get($registrar->getCacheKey());

        $this->assertIsArray($entry);
        $this->assertSame('present', $entry['__hypervel_model_cache']);
        $this->assertIsArray($entry['value']);

        $payload = $entry['value'];

        $permission = collect($payload['permissions'])->first(
            fn (array $permission): bool => $permission['attributes']['name'] === 'edit-articles',
        );

        $this->assertIsArray($permission);
        $this->assertEqualsCanonicalizing(
            [$this->testUserRole->getKey(), $deniedRole->getKey()],
            $permission['roles'],
        );
        $this->assertSame([$deniedRole->getKey()], $permission['denied_roles']);
    }

    public function testCatalogAndViaRoleModelsAreFreedWithoutTheCycleCollector(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole('testRole');
        $registrar = $this->app->make(PermissionRegistrar::class);
        $gcWasEnabled = gc_enabled();

        // With the cycle collector off, only objects without reference cycles are freed.
        gc_disable();

        try {
            $catalogRole = $this->app->make(PermissionContract::class)::findByName('edit-articles')->roles->sole();
            $user = User::findOrFail($this->testUser->getKey());
            $viaRolePermission = $user->getPermissionsViaRoles()->sole();

            $references = [
                WeakReference::create($catalogRole),
                WeakReference::create($catalogRole->getRelation('pivot')),
                WeakReference::create($viaRolePermission),
                WeakReference::create($viaRolePermission->getRelation('pivot')),
            ];

            unset($catalogRole, $user, $viaRolePermission);
            $registrar->clearPermissionsCollection();

            foreach ($references as $reference) {
                $this->assertNull($reference->get());
            }
        } finally {
            if ($gcWasEnabled) {
                gc_enable();
            }
        }
    }

    public function testRoleDeniedPivotHydratesFromGlobalCache(): void
    {
        $this->testUser->assignRole('testRole');
        $this->testUserRole->denyPermissionTo('edit-articles');
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));

        // A new coroutine reads the shared store instead of this coroutine's catalog and memo.
        [$pivot] = parallel([
            fn (): Model => $this->app->make(PermissionContract::class)::findByName('edit-articles')
                ->roles
                ->sole()
                ->getRelation('pivot'),
        ]);

        $this->assertSame($this->testUserPermission->getKey(), $pivot->getAttribute($registrar->pivotPermission));
        $this->assertSame($this->testUserRole->getKey(), $pivot->getAttribute($registrar->pivotRole));
        $this->assertTrue($pivot->getAttribute('is_denied'));

        $registrar->clearPermissionsCollection();

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasDeniedPermissionViaRoles('edit-articles'));
    }

    public function testDirectDeniedPivotHydratesFromModelAssignmentCache(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole($this->testUserRole);
        $this->testUser->denyPermissionTo('edit-articles');

        $user = User::findOrFail($this->testUser->getKey());

        $this->assertFalse($user->relationLoaded('permissions'));
        $this->assertTrue($user->hasDeniedPermission('edit-articles'));
        $this->assertFalse($user->relationLoaded('permissions'));
        $this->assertFalse($user->hasPermissionTo('edit-articles'));
    }

    public function testPermissionCacheResetChangesModelAssignmentCacheToken(): void
    {
        $this->testUser->assignRole('testRole');
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertTrue($this->testUser->hasRole('testRole'));

        $firstToken = $registrar->modelAssignmentCacheToken();

        $this->assertTrue($registrar->forgetCachedPermissions());
        // The database store's forget() reports success even when the key is already gone.
        $this->assertSame($this->usesDatabaseCacheStore(), $registrar->forgetCachedPermissions());

        $this->assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $firstToken);
        $this->assertNotSame($firstToken, $registrar->modelAssignmentCacheToken());
        $this->assertTrue($this->testUser->hasRole('testRole'));
    }

    public function testCatalogOnlyMutationsDoNotRotateTheAssignmentToken(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $token = $registrar->modelAssignmentCacheToken();
        $role = $this->app->make(RoleContract::class)::create(['name' => 'catalog-role']);
        $permission = $this->app->make(PermissionContract::class)::create(['name' => 'catalog-permission']);

        $role->name = 'renamed-catalog-role';
        $role->save();
        $permission->name = 'renamed-catalog-permission';
        $permission->save();
        $role->givePermissionTo($permission);
        $role->revokePermissionTo($permission);

        $this->assertSame($token, $registrar->modelAssignmentCacheToken());
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

    public function testReverseRoleAssignmentsKeepTheWarmDirectPermissionMemo(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $user = User::findOrFail($this->testUser->getKey());
        $directPermission = $user->getDirectPermissions()->sole();

        $this->testUserRole->assignToModels($user);

        $this->assertTrue($user->hasRole('testRole'));
        $this->assertSame($directPermission, $user->getDirectPermissions()->sole());
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

    public function testDirectPermissionHydrationIsMemoizedPerCoroutineAndClearedByMutation(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $user = User::findOrFail($this->testUser->getKey());

        $first = $user->getDirectPermissions()->sole();
        $second = $user->getDirectPermissions()->sole();

        $this->assertSame($first, $second);

        $user->revokePermissionTo('edit-articles');
        $user->givePermissionTo('edit-articles');

        $afterMutation = $user->getDirectPermissions()->sole();
        $this->assertNotSame($first, $afterMutation);

        [$coroutineOne, $coroutineTwo] = parallel([
            fn (): Model => User::findOrFail($user->getKey())->getDirectPermissions()->sole(),
            fn (): Model => User::findOrFail($user->getKey())->getDirectPermissions()->sole(),
        ]);

        $this->assertNotSame($coroutineOne, $coroutineTwo);
    }

    public function testSyncPermissionEffectsInvalidatesWarmModelPermissionCache(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->hasDeniedPermission('edit-articles'));

        $this->testUser->syncPermissionEffects(
            allowed: ['edit-news'],
            denied: ['edit-articles'],
        );

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasDeniedPermission('edit-articles'));
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

    public function testRoleDeniedPermissionMutationsInvalidateWarmGlobalPermissionCatalog(): void
    {
        $this->testUser->assignRole('testRole');
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->hasDeniedPermissionViaRoles('edit-articles'));

        $this->testUserRole->syncPermissionEffects(denied: ['edit-articles']);

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasDeniedPermissionViaRoles('edit-articles'));
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
                'is_denied' => false,
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
