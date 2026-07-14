<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Events;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Events\PermissionAttachedEvent;
use Hypervel\Permission\Events\PermissionDetachedEvent;
use Hypervel\Permission\Events\RoleAttachedEvent;
use Hypervel\Permission\Events\RoleDetachedEvent;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;
use Mockery as m;

class EventTest extends TestCase
{
    public function testRoleAttachedEventIsNotDispatchedWhenEventsAreDisabled(): void
    {
        Event::fake([RoleAttachedEvent::class]);

        $this->testUser->assignRole('testRole');

        Event::assertNotDispatched(RoleAttachedEvent::class);
    }

    public function testRoleAttachedEventChecksListenersBeforeDispatching(): void
    {
        $this->app->make('config')->set('permission.events_enabled', true);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->byDefault()->andReturnFalse();
        $events->shouldReceive('hasListeners')->once()->with(RoleAttachedEvent::class)->andReturnFalse();
        $events->shouldReceive('dispatch')->with(m::type(RoleAttachedEvent::class), m::any(), m::any())->never();

        $this->app->instance('events', $events);
        $this->app->instance(Dispatcher::class, $events);

        $this->testUser->assignRole('testRole');
    }

    public function testRoleAttachedEventIsDispatchedWhenEnabledAndListenedFor(): void
    {
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([RoleAttachedEvent::class]);

        $this->testUser->assignRole('testRole');

        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event): bool {
            return $event->model->is($this->testUser)
                && $event->rolesOrIds === [$this->testUserRole->getKey()];
        });
    }

    public function testRoleDetachedEventIsDispatchedWhenEnabledAndListenedFor(): void
    {
        $this->testUser->assignRole('testRole');
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([RoleDetachedEvent::class]);

        $this->testUser->removeRole('testRole');

        Event::assertDispatched(RoleDetachedEvent::class, function (RoleDetachedEvent $event): bool {
            return $event->model->is($this->testUser)
                && $event->rolesOrIds === [$this->testUserRole->getKey()];
        });
    }

    public function testPermissionAttachedEventIsDispatchedWhenEnabledAndListenedFor(): void
    {
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([PermissionAttachedEvent::class]);

        $this->testUser->givePermissionTo('edit-articles');

        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event): bool {
            return $event->model->is($this->testUser)
                && $event->permissionsOrIds === [$this->testUserPermission->getKey()];
        });
    }

    public function testPermissionAttachedEventListenerSeesFreshWildcardIndexAfterPermissionAttach(): void
    {
        $this->app->make('config')->set('permission.enable_wildcard_permission', true);
        $this->app->make('config')->set('permission.events_enabled', true);
        $this->flushPermissionState();

        $this->assertFalse($this->testUser->hasPermissionTo('posts.create'));

        $listenerSawPermission = false;
        Event::listen(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use (&$listenerSawPermission): void {
            if ($event->model->is($this->testUser)) {
                $listenerSawPermission = $event->model->hasPermissionTo('posts.create');
            }
        });

        $this->app->make(PermissionContract::class)::create(['name' => 'posts.*']);
        $this->testUser->givePermissionTo('posts.*');

        $this->assertTrue($listenerSawPermission);
    }

    public function testSyncPermissionsDispatchesPermissionAttachedEventOnce(): void
    {
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([PermissionAttachedEvent::class]);

        $this->testUser->syncPermissions('edit-articles');

        Event::assertDispatchedTimes(PermissionAttachedEvent::class, 1);
        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event): bool {
            return $event->model->is($this->testUser)
                && $event->permissionsOrIds === [$this->testUserPermission->getKey()];
        });
    }

    public function testPermissionAttachedEventListenerSeesFreshWildcardIndexAfterPermissionSync(): void
    {
        $this->app->make('config')->set('permission.enable_wildcard_permission', true);
        $this->app->make('config')->set('permission.events_enabled', true);
        $this->flushPermissionState();

        $this->assertFalse($this->testUser->hasPermissionTo('posts.create'));

        $listenerSawPermission = false;
        Event::listen(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use (&$listenerSawPermission): void {
            if ($event->model->is($this->testUser)) {
                $listenerSawPermission = $event->model->hasPermissionTo('posts.create');
            }
        });

        $this->app->make(PermissionContract::class)::create(['name' => 'posts.*']);
        $this->testUser->syncPermissions('posts.*');

        $this->assertTrue($listenerSawPermission);
    }

    public function testSyncPermissionsWithForbiddenDispatchesPermissionAttachedEventOnce(): void
    {
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([PermissionAttachedEvent::class]);

        $this->testUser->syncPermissionsWithForbidden(
            allowed: ['edit-articles'],
            forbidden: ['edit-news'],
        );

        $editNewsPermission = $this->app->make(PermissionContract::class)::findByName('edit-news');

        Event::assertDispatchedTimes(PermissionAttachedEvent::class, 1);
        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use ($editNewsPermission): bool {
            return $event->model->is($this->testUser)
                && $event->permissionsOrIds === [$this->testUserPermission->getKey(), $editNewsPermission->getKey()];
        });
    }

    public function testDeferredAssignmentsDispatchAtTheCallBoundaryWithoutSavedCallbackDuplicates(): void
    {
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([
            PermissionAttachedEvent::class,
            PermissionDetachedEvent::class,
            RoleAttachedEvent::class,
            RoleDetachedEvent::class,
        ]);

        $user = new User(['email' => 'queued-event@example.com']);

        $user->givePermissionTo('edit-articles');
        $user->assignRole('testRole');
        $user->revokePermissionTo('edit-articles');
        $user->removeRole('testRole');
        $user->save();

        Event::assertDispatchedTimes(PermissionAttachedEvent::class, 1);
        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use ($user): bool {
            return $event->model->is($user)
                && $event->permissionsOrIds === [$this->testUserPermission->getKey()];
        });
        Event::assertDispatchedTimes(PermissionDetachedEvent::class, 1);
        Event::assertDispatched(PermissionDetachedEvent::class, function (PermissionDetachedEvent $event) use ($user): bool {
            $permission = $event->permissionsOrIds;

            return $event->model->is($user)
                && $permission instanceof PermissionContract
                && $permission->getKey() === $this->testUserPermission->getKey();
        });
        Event::assertDispatchedTimes(RoleAttachedEvent::class, 1);
        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event) use ($user): bool {
            return $event->model->is($user)
                && $event->rolesOrIds === [$this->testUserRole->getKey()];
        });
        Event::assertDispatchedTimes(RoleDetachedEvent::class, 1);
        Event::assertDispatched(RoleDetachedEvent::class, function (RoleDetachedEvent $event) use ($user): bool {
            return $event->model->is($user)
                && $event->rolesOrIds === [$this->testUserRole->getKey()];
        });
    }

    public function testDeferredForbiddenPermissionSyncDispatchesOnceBeforeSave(): void
    {
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([PermissionAttachedEvent::class]);

        $user = new User(['email' => 'queued-forbidden-event@example.com']);

        $user->syncPermissionsWithForbidden(
            allowed: ['edit-articles'],
            forbidden: ['edit-news'],
        );
        $user->save();

        $editNewsPermission = $this->app->make(PermissionContract::class)::findByName('edit-news');

        Event::assertDispatchedTimes(PermissionAttachedEvent::class, 1);
        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use ($user, $editNewsPermission): bool {
            return $event->model->is($user)
                && $event->permissionsOrIds === [$this->testUserPermission->getKey(), $editNewsPermission->getKey()];
        });
    }

    public function testPermissionDetachedEventIsDispatchedWhenEnabledAndListenedFor(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([PermissionDetachedEvent::class]);

        $this->testUser->revokePermissionTo('edit-articles');

        Event::assertDispatched(PermissionDetachedEvent::class, function (PermissionDetachedEvent $event): bool {
            $permission = $event->permissionsOrIds;

            return $event->model->is($this->testUser)
                && $permission instanceof PermissionContract
                && $permission->getKey() === $this->testUserPermission->getKey();
        });
    }

    public function testNoOpSavedAssignmentsDispatchRequestedPayloads(): void
    {
        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-articles');
        $testRole2 = $this->app->make(RoleContract::class)::findByName('testRole2');
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([
            PermissionAttachedEvent::class,
            PermissionDetachedEvent::class,
            RoleAttachedEvent::class,
            RoleDetachedEvent::class,
        ]);

        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->removeRole('testRole2');
        $this->testUser->revokePermissionTo('edit-news');

        $editNewsPermission = $this->app->make(PermissionContract::class)::findByName('edit-news');

        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event): bool {
            return $event->model->is($this->testUser)
                && $event->permissionsOrIds === [$this->testUserPermission->getKey()];
        });
        Event::assertDispatched(PermissionDetachedEvent::class, function (PermissionDetachedEvent $event) use ($editNewsPermission): bool {
            return $event->model->is($this->testUser)
                && $event->permissionsOrIds === $editNewsPermission;
        });
        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event): bool {
            return $event->model->is($this->testUser)
                && $event->rolesOrIds === [$this->testUserRole->getKey()];
        });
        Event::assertDispatched(RoleDetachedEvent::class, function (RoleDetachedEvent $event) use ($testRole2): bool {
            return $event->model->is($this->testUser)
                && $event->rolesOrIds === [$testRole2->getKey()];
        });
    }

    public function testEmptyAssignmentsDispatchRequestedPayloads(): void
    {
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([PermissionAttachedEvent::class, RoleAttachedEvent::class]);

        $this->testUser->assignRole();
        $this->testUser->givePermissionTo();

        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event): bool {
            return $event->model->is($this->testUser) && $event->rolesOrIds === [];
        });
        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event): bool {
            return $event->model->is($this->testUser) && $event->permissionsOrIds === [];
        });
    }

    public function testRoleSyncDispatchesCurrentDetachedAndRequestedAttachedPayloads(): void
    {
        $this->testUser->assignRole('testRole');
        $role = $this->app->make(RoleContract::class)::create(['name' => 'testRole3']);
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([RoleAttachedEvent::class, RoleDetachedEvent::class]);

        $this->testUser->syncRoles('testRole', 'testRole3');

        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event) use ($role): bool {
            return $event->model->is($this->testUser)
                && $event->rolesOrIds === [$this->testUserRole->getKey(), $role->getKey()];
        });
        Event::assertDispatched(RoleDetachedEvent::class, function (RoleDetachedEvent $event): bool {
            return $event->model->is($this->testUser)
                && $event->rolesOrIds === [$this->testUserRole->getKey()];
        });
    }

    public function testPermissionSyncDispatchesRequestedAttachedPayloadOnly(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $permission = $this->app->make(PermissionContract::class)::findByName('edit-news');
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([PermissionAttachedEvent::class, PermissionDetachedEvent::class]);

        $this->testUser->syncPermissions('edit-articles', 'edit-news');

        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use ($permission): bool {
            return $event->model->is($this->testUser)
                && $event->permissionsOrIds === [$this->testUserPermission->getKey(), $permission->getKey()];
        });
        Event::assertNotDispatched(PermissionDetachedEvent::class);
    }

    public function testPermissionEffectUpdateDispatchesTheChangedPermission(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->app->make('config')->set('permission.events_enabled', true);

        Event::fake([PermissionAttachedEvent::class, PermissionDetachedEvent::class]);

        $this->testUser->giveForbiddenTo('edit-articles');

        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event): bool {
            return $event->model->is($this->testUser)
                && $event->permissionsOrIds === [$this->testUserPermission->getKey()];
        });
        Event::assertNotDispatched(PermissionDetachedEvent::class);
    }
}
