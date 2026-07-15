<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Permission\Events\RoleDetachedEvent;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\PartitionTestCase;

class PartitionQueryCountTest extends PartitionTestCase
{
    public function testColdCatalogKeepsThreeQueriesAndAddsPartitionPredicates(): void
    {
        PartitionedRole::create(['name' => 'editor']);
        PartitionedPermission::create(['name' => 'articles.edit']);
        $registrar = $this->app->make(PermissionRegistrar::class);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $registrar->getPermissions();

        $queries = DB::getQueryLog();

        $this->assertCount(3, $queries);

        foreach ($queries as $query) {
            $this->assertStringContainsString('workspace_id', $query['query']);
            $this->assertContains(self::PARTITION_A, $query['bindings']);
        }
    }

    public function testWarmCatalogAndAuthorizationChecksRemainQueryFree(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'editor']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        $this->assertTrue($user->hasPermissionTo($permission));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($user->hasPermissionTo($permission));
        $this->app->make(PermissionRegistrar::class)->getPermissions();

        $this->assertSame([], DB::getQueryLog());
    }

    public function testColdAuthorizationRetainsCatalogAndAssignmentQueryShape(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'editor']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($user->hasPermissionTo('articles.edit'));

        $queries = DB::getQueryLog();

        $this->assertCount(5, $queries);
        $this->assertSame(
            5,
            count(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'workspace_id'))),
        );
    }

    public function testResolverLookupAndOrdinaryMutationsAddNoDiscoveryQuery(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'editor']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $partition = $registrar->resolvePartition();

        $this->assertNotNull($partition);
        $this->assertSame([], DB::getQueryLog());

        $user->assignRole($role);

        $queries = DB::getQueryLog();

        $this->assertNotEmpty($queries);
        $discoveryQueries = array_filter($queries, static function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'distinct')
                && (str_contains($sql, Config::modelHasRolesTable())
                    || str_contains($sql, Config::modelHasPermissionsTable()));
        });

        $this->assertSame([], $discoveryQueries);
    }

    public function testRoleSyncUsesOneDeleteAndOneBulkInsertWithoutListeners(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'roles@example.com']);
        $firstRole = PartitionedRole::create(['name' => 'editor']);
        $secondRole = PartitionedRole::create(['name' => 'publisher']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $user->syncRoles([$firstRole, $secondRole]);

        $queries = DB::getQueryLog();

        $this->assertCount(2, $queries);

        foreach ($queries as $query) {
            $this->assertStringContainsString('workspace_id', $query['query']);
            $this->assertContains(self::PARTITION_A, $query['bindings']);
        }

        $this->assertStringContainsString('delete from', strtolower($queries[0]['query']));
        $this->assertStringContainsString('model_has_roles', $queries[0]['query']);
        $this->assertStringContainsString('insert into', strtolower($queries[1]['query']));
    }

    public function testRoleSyncAddsOnePivotOnlyReadForTheDetachedEventPayload(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'role-events@example.com']);
        $currentRole = PartitionedRole::create(['name' => 'editor']);
        $replacementRole = PartitionedRole::create(['name' => 'publisher']);
        $user->assignRole($currentRole);
        $this->app->make('config')->set('permission.events_enabled', true);
        Event::fake([RoleDetachedEvent::class]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $user->syncRoles($replacementRole);

        $queries = DB::getQueryLog();

        $this->assertCount(3, $queries);
        $this->assertStringContainsString('select', strtolower($queries[0]['query']));
        $this->assertStringContainsString('model_has_roles', $queries[0]['query']);
        $this->assertStringNotContainsString(' join ', strtolower($queries[0]['query']));
        $this->assertStringContainsString('delete from', strtolower($queries[1]['query']));
        $this->assertStringContainsString('insert into', strtolower($queries[2]['query']));
        Event::assertDispatched(
            RoleDetachedEvent::class,
            fn (RoleDetachedEvent $event): bool => $event->rolesOrIds === [$currentRole->getKey()],
        );
    }

    public function testPermissionSyncUsesAPivotOnlyReadAndOneBulkInsert(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'permissions@example.com']);
        $firstPermission = PartitionedPermission::create(['name' => 'articles.edit']);
        $secondPermission = PartitionedPermission::create(['name' => 'articles.publish']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $user->syncPermissions([$firstPermission, $secondPermission]);

        $queries = DB::getQueryLog();

        $this->assertCount(2, $queries);

        foreach ($queries as $query) {
            $this->assertStringContainsString('workspace_id', $query['query']);
            $this->assertContains(self::PARTITION_A, $query['bindings']);
        }

        $pivotRead = strtolower($queries[0]['query']);

        $this->assertStringContainsString('model_has_permissions', $pivotRead);
        $this->assertStringContainsString('permission_test_id', $pivotRead);
        $this->assertStringNotContainsString(' join ', $pivotRead);
        $this->assertStringContainsString('insert into', strtolower($queries[1]['query']));
    }

    public function testPermissionEffectSyncBatchesMixedFlipsIntoTwoUpdates(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'permission-effects@example.com']);
        $toDeniedOne = PartitionedPermission::create(['name' => 'articles.deny-one']);
        $toDeniedTwo = PartitionedPermission::create(['name' => 'articles.deny-two']);
        $toAllowedOne = PartitionedPermission::create(['name' => 'articles.allow-one']);
        $toAllowedTwo = PartitionedPermission::create(['name' => 'articles.allow-two']);
        $unchangedAllowed = PartitionedPermission::create(['name' => 'articles.allowed']);
        $unchangedDenied = PartitionedPermission::create(['name' => 'articles.denied']);

        $user->syncPermissionEffects(
            [$toDeniedOne, $toDeniedTwo, $unchangedAllowed],
            [$toAllowedOne, $toAllowedTwo, $unchangedDenied],
        );

        DB::enableQueryLog();
        DB::flushQueryLog();

        $changes = $user->syncPermissionEffects(
            [$toAllowedOne, $toAllowedTwo, $unchangedAllowed],
            [$toDeniedOne, $toDeniedTwo, $unchangedDenied],
        );
        $queries = DB::getQueryLog();

        $this->assertSame([
            'attached' => [],
            'detached' => [],
            'updated' => [
                $toAllowedOne->getKey(),
                $toAllowedTwo->getKey(),
                $toDeniedOne->getKey(),
                $toDeniedTwo->getKey(),
            ],
        ], $changes);
        $this->assertCount(3, $queries);
        $this->assertStringContainsString('select', strtolower($queries[0]['query']));
        $this->assertStringContainsString('update', strtolower($queries[1]['query']));
        $this->assertStringContainsString('update', strtolower($queries[2]['query']));

        $this->assertContains($toAllowedOne->getKey(), $queries[1]['bindings']);
        $this->assertContains($toAllowedTwo->getKey(), $queries[1]['bindings']);
        $this->assertNotContains($unchangedAllowed->getKey(), $queries[1]['bindings']);
        $this->assertContains($toDeniedOne->getKey(), $queries[2]['bindings']);
        $this->assertContains($toDeniedTwo->getKey(), $queries[2]['bindings']);
        $this->assertNotContains($unchangedDenied->getKey(), $queries[2]['bindings']);
    }

    public function testRoleRemovalWithoutAListenerUsesOneBlindDelete(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'blind-delete@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($role);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $user->removeRole($role);

        $queries = DB::getQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('delete from', strtolower($queries[0]['query']));
        $this->assertStringContainsString('workspace_id', $queries[0]['query']);
    }

    public function testSingleRoleRemovalWithAListenerUsesOneDelete(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'single-delete@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($role);
        $this->app->make('config')->set('permission.events_enabled', true);
        Event::fake([RoleDetachedEvent::class]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $user->removeRole($role);

        $queries = DB::getQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('delete from', strtolower($queries[0]['query']));
        Event::assertDispatched(
            RoleDetachedEvent::class,
            fn (RoleDetachedEvent $event): bool => $event->rolesOrIds === [$role->getKey()],
        );
    }

    public function testMultipleRoleRemovalWithAListenerUsesOneBlindDelete(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'multiple-delete@example.com']);
        $firstRole = PartitionedRole::create(['name' => 'editor']);
        $secondRole = PartitionedRole::create(['name' => 'publisher']);
        $user->assignRole($firstRole, $secondRole);
        $this->app->make('config')->set('permission.events_enabled', true);
        Event::fake([RoleDetachedEvent::class]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $user->removeRole($secondRole, $firstRole);

        $queries = DB::getQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('model_has_roles', $queries[0]['query']);
        $this->assertStringContainsString('delete from', strtolower($queries[0]['query']));
        Event::assertDispatched(
            RoleDetachedEvent::class,
            fn (RoleDetachedEvent $event): bool => $event->rolesOrIds === [
                $secondRole->getKey(),
                $firstRole->getKey(),
            ],
        );
    }

    public function testEmptyMultipleRoleRemovalWithAListenerStillReportsTheRequest(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'empty-delete@example.com']);
        $firstRole = PartitionedRole::create(['name' => 'editor']);
        $secondRole = PartitionedRole::create(['name' => 'publisher']);
        $this->app->make('config')->set('permission.events_enabled', true);
        Event::fake([RoleDetachedEvent::class]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $user->removeRole($firstRole, $secondRole);

        $queries = DB::getQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('model_has_roles', $queries[0]['query']);
        $this->assertStringContainsString('delete from', strtolower($queries[0]['query']));
        Event::assertDispatched(
            RoleDetachedEvent::class,
            fn (RoleDetachedEvent $event): bool => $event->rolesOrIds === [
                $firstRole->getKey(),
                $secondRole->getKey(),
            ],
        );
    }
}
