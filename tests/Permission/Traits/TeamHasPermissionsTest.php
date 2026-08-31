<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Permission\Events\PermissionAttachedEvent;
use Hypervel\Permission\Events\PermissionDetachedEvent;
use Hypervel\Permission\Exceptions\TeamNotSelected;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Permission\Fixtures\Models\User;

class TeamHasPermissionsTest extends HasPermissionsTest
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.teams', true);
    }

    protected function setUpInCoroutine(): void
    {
        $this->setUpTeams();
    }

    public function testItCanAssignSameAndDifferentPermissionsOnSameUserOnDifferentTeams(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->givePermissionTo('edit-articles', 'edit-news');

        setPermissionsTeamId(2);
        $this->testUser->givePermissionTo('edit-articles', 'edit-blog');

        setPermissionsTeamId(1);
        $this->testUser->load('permissions');
        $this->assertSame(['edit-articles', 'edit-news'], $this->testUser->getPermissionNames()->sort()->values()->all());
        $this->assertTrue($this->testUser->hasAllDirectPermissions(['edit-articles', 'edit-news']));
        $this->assertFalse($this->testUser->hasAllDirectPermissions(['edit-articles', 'edit-blog']));

        setPermissionsTeamId(2);
        $this->testUser->load('permissions');
        $this->assertSame(['edit-articles', 'edit-blog'], $this->testUser->getPermissionNames()->sort()->values()->all());
        $this->assertTrue($this->testUser->hasAllDirectPermissions(['edit-articles', 'edit-blog']));
        $this->assertFalse($this->testUser->hasAllDirectPermissions(['edit-articles', 'edit-news']));
    }

    public function testItCanListAllCoupledPermissionsDirectlyAndViaRolesOnSameUserOnDifferentTeams(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-news');

        setPermissionsTeamId(2);
        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-blog');

        setPermissionsTeamId(1);
        $this->testUser->load('roles', 'permissions');
        $this->assertSame(['edit-articles', 'edit-news'], $this->testUser->getAllPermissions()->pluck('name')->sort()->values()->all());

        setPermissionsTeamId(2);
        $this->testUser->load('roles', 'permissions');
        $this->assertSame(['edit-articles', 'edit-blog'], $this->testUser->getAllPermissions()->pluck('name')->sort()->values()->all());
    }

    public function testWarmAuthorizationReusesHydratedCatalogRelationsAcrossTeams(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);

        setPermissionsTeamId(1);
        $this->testUser->assignRole($this->testUserRole);

        setPermissionsTeamId(2);
        $this->testUser->assignRole($this->testUserRole);

        setPermissionsTeamId(1);
        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
        $this->assertSame(
            [$this->testUserPermission->name],
            $this->testUser->getAllPermissions()->pluck('name')->all(),
        );

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
        $this->testUser->getAllPermissions();
        $this->assertSame([], DB::getQueryLog());

        setPermissionsTeamId(2);
        DB::flushQueryLog();

        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
        $this->testUser->getAllPermissions();
        $this->assertCount(2, DB::getQueryLog());

        DB::flushQueryLog();

        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
        $this->testUser->getAllPermissions();
        $this->assertSame([], DB::getQueryLog());
    }

    public function testDirectPermissionHydrationMemoIsSeparatedByTeam(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->givePermissionTo($this->testUserPermission);
        $teamOne = $this->testUser->getDirectPermissions()->sole();
        $this->assertSame($teamOne, $this->testUser->getDirectPermissions()->sole());

        setPermissionsTeamId(2);
        $this->testUser->givePermissionTo($this->testUserPermission);
        $teamTwo = $this->testUser->getDirectPermissions()->sole();
        $this->assertNotSame($teamOne, $teamTwo);

        setPermissionsTeamId(1);
        $this->assertSame($teamOne, $this->testUser->getDirectPermissions()->sole());
    }

    public function testItCanSyncOrRemovePermissionsWithoutDetachingDifferentTeams(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->syncPermissions('edit-articles', 'edit-news');

        setPermissionsTeamId(2);
        $this->testUser->syncPermissions('edit-articles', 'edit-blog');

        setPermissionsTeamId(1);
        $this->testUser->load('permissions');
        $this->assertSame(['edit-articles', 'edit-news'], $this->testUser->getPermissionNames()->sort()->values()->all());

        $this->testUser->revokePermissionTo('edit-articles');
        $this->assertSame(['edit-news'], $this->testUser->getPermissionNames()->sort()->values()->all());

        setPermissionsTeamId(2);
        $this->testUser->load('permissions');
        $this->assertSame(['edit-articles', 'edit-blog'], $this->testUser->getPermissionNames()->sort()->values()->all());
    }

    public function testItCanScopeUsersOnDifferentTeams(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        setPermissionsTeamId(2);
        $user1->givePermissionTo(['edit-articles', 'edit-news']);
        $this->testUserRole->givePermissionTo('edit-articles');
        $user2->assignRole('testRole');

        setPermissionsTeamId(1);
        $user1->givePermissionTo(['edit-articles']);

        setPermissionsTeamId(2);
        $this->assertCount(2, User::permission(['edit-articles', 'edit-news'])->get());
        $this->assertCount(1, User::permission('edit-news')->get());

        setPermissionsTeamId(1);
        $this->assertCount(1, User::permission(['edit-articles', 'edit-news'])->get());
        $this->assertCount(0, User::permission('edit-news')->get());
    }

    public function testDeniedPermissionFlipsExistingAllowedPermissionForCurrentTeamOnly(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->denyPermissionTo('edit-articles');

        setPermissionsTeamId(2);
        $this->testUser->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertTrue($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertSame([], $this->testUser->getPermissionNames()->all());

        setPermissionsTeamId(2);
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertFalse($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertSame(['edit-articles'], $this->testUser->getPermissionNames()->all());
    }

    public function testAllowedPermissionFlipsExistingDeniedPermissionForCurrentTeamOnly(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->denyPermissionTo('edit-articles');
        $this->testUser->givePermissionTo('edit-articles');

        setPermissionsTeamId(2);
        $this->testUser->denyPermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertFalse($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));

        setPermissionsTeamId(2);
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertTrue($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
    }

    public function testQueuedPermissionAssignmentsKeepSeparateTeamEdges(): void
    {
        $user = new User(['email' => 'queued-teams@example.com']);

        setPermissionsTeamId(1);
        $user->givePermissionTo('edit-articles');

        setPermissionsTeamId(2);
        $user->givePermissionTo('edit-articles');

        $user->save();

        setPermissionsTeamId(1);
        $this->assertTrue($user->hasPermissionTo('edit-articles'));
        $this->assertSame(1, $user->permissions()->count());

        setPermissionsTeamId(2);
        $user->unsetRelation('permissions');
        $this->assertTrue($user->hasPermissionTo('edit-articles'));
        $this->assertSame(1, $user->permissions()->count());
    }

    public function testQueuedSyncPermissionsReplacesOnlyCurrentTeamPermissionAssignments(): void
    {
        $user = new User(['email' => 'queued-team-sync@example.com']);

        setPermissionsTeamId(1);
        $user->givePermissionTo('edit-news');

        setPermissionsTeamId(2);
        $user->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $user->syncPermissions('edit-blog');

        $user->save();

        setPermissionsTeamId(1);
        $user->unsetRelation('permissions');
        $this->assertSame(['edit-blog'], $user->getPermissionNames()->all());
        $this->assertFalse($user->hasPermissionTo('edit-news'));

        setPermissionsTeamId(2);
        $user->unsetRelation('permissions');
        $this->assertSame(['edit-articles'], $user->getPermissionNames()->all());
    }

    public function testQueuedDeniedSyncReplacesOnlyCurrentTeamPermissionAssignments(): void
    {
        $user = new User(['email' => 'queued-team-denied-sync@example.com']);

        setPermissionsTeamId(1);
        $user->givePermissionTo('edit-news');

        setPermissionsTeamId(2);
        $user->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $changes = $user->syncPermissionEffects(
            allowed: ['edit-blog'],
            denied: ['edit-articles'],
        );

        $this->assertSame(['attached' => [], 'detached' => [], 'updated' => []], $changes);

        $user->save();

        setPermissionsTeamId(1);
        $user->unsetRelation('permissions');
        $this->assertSame(['edit-blog'], $user->getPermissionNames()->all());
        $this->assertTrue($user->hasDeniedPermission('edit-articles'));
        $this->assertFalse($user->hasPermissionTo('edit-news'));

        setPermissionsTeamId(2);
        $user->unsetRelation('permissions');
        $this->assertSame(['edit-articles'], $user->getPermissionNames()->all());
        $this->assertFalse($user->hasDeniedPermission('edit-articles'));
    }

    public function testItRevokesDeniedPermissionsForCurrentTeamOnly(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->denyPermissionTo('edit-articles');

        setPermissionsTeamId(2);
        $this->testUser->denyPermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->testUser->revokePermissionTo('edit-articles');
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(0, $this->testUser->permissions()->count());
        $this->assertFalse($this->testUser->hasDeniedPermission('edit-articles'));

        setPermissionsTeamId(2);
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertTrue($this->testUser->hasDeniedPermission('edit-articles'));
    }

    public function testPermissionScopeUsesDirectPermissionEffectForCurrentTeamOnly(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->denyPermissionTo('edit-articles');

        setPermissionsTeamId(2);
        $this->testUser->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->assertFalse(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
        $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));

        setPermissionsTeamId(2);
        $this->assertTrue(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
        $this->assertFalse(User::withoutPermission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeUsesRoleAssignmentsForCurrentTeamOnly(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->testUser->assignRole($this->testUserRole);

        $this->assertTrue(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));

        setPermissionsTeamId(2);
        $this->assertFalse(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionReplacementEventsStayInsideTheCurrentTeam(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->givePermissionTo('edit-articles');

        setPermissionsTeamId(2);
        $this->testUser->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->app->make('config')->set('permission.events_enabled', true);
        $events = [];

        Event::listen(PermissionDetachedEvent::class, function (PermissionDetachedEvent $event) use (&$events): void {
            $events[] = 'detached';
            $this->assertSame(['edit-articles'], $event->permissionsOrIds->pluck('name')->all());
            $this->assertFalse($event->model->hasDirectPermission('edit-articles'));
            $this->assertTrue($event->model->hasDirectPermission('edit-news'));
        });
        Event::listen(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use (&$events): void {
            $events[] = 'attached';
            $this->assertFalse($event->model->hasDirectPermission('edit-articles'));
            $this->assertTrue($event->model->hasDirectPermission('edit-news'));
        });

        $this->testUser->syncPermissions('edit-news');

        $this->assertSame(['detached', 'attached'], $events);

        setPermissionsTeamId(2);
        $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasDirectPermission('edit-news'));
    }

    public function testWarmPermissionPivotMatchesTheCapturedTeamConstraint(): void
    {
        setPermissionsTeamId(1);

        $this->assertWarmPermissionPivotMatchesRelationPivot(
            $this->testUser,
            $this->testUserPermission,
        );
    }

    public function testWarmPermissionPivotMatchesTheCapturedGlobalTeamConstraint(): void
    {
        setPermissionsTeamId(null);

        $this->assertWarmPermissionPivotMatchesRelationPivot(
            $this->testUser,
            $this->testUserPermission,
        );
    }

    public function testPermissionMutationsRequireASelectedTeam(): void
    {
        setPermissionsTeamId(null);
        $unsavedUser = new User(['email' => 'teamless-queued-permission@example.com']);

        $this->assertTeamNotSelected(fn () => $this->testUser->givePermissionTo('edit-articles'));
        $this->assertTeamNotSelected(fn () => $this->testUser->denyPermissionTo('edit-articles'));
        $this->assertTeamNotSelected(fn () => $this->testUser->revokePermissionTo('edit-articles'));
        $this->assertTeamNotSelected(fn () => $this->testUser->syncPermissions());
        $this->assertTeamNotSelected(fn () => $this->testUser->syncPermissionEffects());
        $this->assertTeamNotSelected(fn () => $unsavedUser->givePermissionTo('edit-articles'));
    }

    public function testDirectPermissionRelationWritesRequireASelectedTeam(): void
    {
        setPermissionsTeamId(null);
        $relation = $this->testUser->permissions();
        $permissionId = $this->testUserPermission->getKey();

        $this->assertTeamNotSelected(fn () => $relation->attach($permissionId));
        $this->assertTeamNotSelected(fn () => $relation->detach($permissionId));
        $this->assertTeamNotSelected(fn () => $relation->toggle($permissionId));
        $this->assertTeamNotSelected(fn () => $relation->sync([$permissionId]));
        $this->assertTeamNotSelected(fn () => $relation->updateExistingPivot($permissionId, []));
    }

    /**
     * Assert a team-scoped mutation rejects missing team context.
     */
    private function assertTeamNotSelected(Closure $mutation): void
    {
        try {
            $mutation();
            $this->fail('Expected the teamless mutation to be rejected.');
        } catch (TeamNotSelected $exception) {
            $this->assertStringContainsString('current team', $exception->getMessage());
        }
    }

    /**
     * Assert a compact-cache pivot retains the selected relation's identity constraints.
     */
    private function assertWarmPermissionPivotMatchesRelationPivot(Model $user, Model $permission): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->rememberModelPermissionAssignments($user, fn (): array => [[
            $permission->getKeyName() => $permission->getKey(),
            'is_denied' => false,
        ]]);

        $warmPermission = $user->getDirectPermissions()->sole();
        $warmPivot = $warmPermission->getRelation('pivot');

        $this->assertInstanceOf(MorphPivot::class, $warmPivot);
        $this->assertSame(Config::morphKey(), $warmPivot->getForeignKey());
        $this->assertSame($registrar->pivotPermission, $warmPivot->getRelatedKey());
        $this->assertSame(Config::MORPH_TYPE, $warmPivot->getMorphType());

        $relationPivot = $user->permissions()->newExistingPivot($warmPivot->getAttributes());
        $warmQuery = (new ClassInvoker($warmPivot))->getDeleteQuery();
        $relationQuery = (new ClassInvoker($relationPivot))->getDeleteQuery();

        $this->assertSame($relationQuery->toSql(), $warmQuery->toSql());
        $this->assertSame($relationQuery->getBindings(), $warmQuery->getBindings());
    }
}
