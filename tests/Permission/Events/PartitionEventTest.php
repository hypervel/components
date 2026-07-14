<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Events;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Permission\Events\PermissionAttachedEvent;
use Hypervel\Permission\Events\PermissionDetachedEvent;
use Hypervel\Permission\Events\RoleAttachedEvent;
use Hypervel\Permission\Events\RoleDetachedEvent;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\PartitionTestCase;
use ReflectionClass;
use ReflectionParameter;

class PartitionEventTest extends PartitionTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.events_enabled', true);
    }

    public function testPartitionedRoleEventsKeepTheirExistingShape(): void
    {
        Event::fake([RoleAttachedEvent::class, RoleDetachedEvent::class]);

        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);

        $user->assignRole($role);

        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event) use ($user, $role): bool {
            return $event->model->is($user)
                && $event->rolesOrIds === [$role->getKey()];
        });

        $user->removeRole($role);

        Event::assertDispatched(RoleDetachedEvent::class, function (RoleDetachedEvent $event) use ($user, $role): bool {
            return $event->model->is($user)
                && $event->rolesOrIds === [$role->getKey()];
        });

        $this->assertEventConstructorIsUnchanged(RoleAttachedEvent::class, 'rolesOrIds');
        $this->assertEventConstructorIsUnchanged(RoleDetachedEvent::class, 'rolesOrIds');
    }

    public function testPartitionedPermissionEventsKeepTheirExistingShape(): void
    {
        Event::fake([PermissionAttachedEvent::class, PermissionDetachedEvent::class]);

        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);

        $user->givePermissionTo($permission);

        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use ($user, $permission): bool {
            return $event->model->is($user)
                && $event->permissionsOrIds === [$permission->getKey()];
        });

        $user->revokePermissionTo($permission);

        Event::assertDispatched(PermissionDetachedEvent::class, function (PermissionDetachedEvent $event) use ($user, $permission): bool {
            return $event->model->is($user)
                && $event->permissionsOrIds === $permission;
        });

        $this->assertEventConstructorIsUnchanged(PermissionAttachedEvent::class, 'permissionsOrIds');
        $this->assertEventConstructorIsUnchanged(PermissionDetachedEvent::class, 'permissionsOrIds');
    }

    public function testPartitionedNoOpAndSyncEventsPreserveRequestedUuidPayloads(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $member = PartitionedRole::create(['name' => 'member']);
        $owner = PartitionedRole::create(['name' => 'owner']);
        $edit = PartitionedPermission::create(['name' => 'articles.edit']);
        $publish = PartitionedPermission::create(['name' => 'articles.publish']);

        $user->assignRole($member);
        $user->givePermissionTo($edit);

        Event::fake([RoleAttachedEvent::class, PermissionAttachedEvent::class]);

        $user->assignRole($member);
        $user->givePermissionTo($edit);

        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event) use ($user, $member): bool {
            return $event->model->is($user)
                && $event->rolesOrIds === [$member->getKey()];
        });
        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use ($user, $edit): bool {
            return $event->model->is($user)
                && $event->permissionsOrIds === [$edit->getKey()];
        });

        Event::fake([
            PermissionAttachedEvent::class,
            PermissionDetachedEvent::class,
            RoleAttachedEvent::class,
            RoleDetachedEvent::class,
        ]);

        $user->syncRoles($member, $owner);
        $user->syncPermissions($edit, $publish);

        Event::assertDispatched(RoleDetachedEvent::class, function (RoleDetachedEvent $event) use ($user, $member): bool {
            return $event->model->is($user)
                && $event->rolesOrIds === [$member->getKey()];
        });
        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event) use ($user, $member, $owner): bool {
            return $event->model->is($user)
                && $event->rolesOrIds === [$member->getKey(), $owner->getKey()];
        });
        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use ($user, $edit, $publish): bool {
            return $event->model->is($user)
                && $event->permissionsOrIds === [$edit->getKey(), $publish->getKey()];
        });
        Event::assertNotDispatched(PermissionDetachedEvent::class);
    }

    /**
     * Assert a permission assignment event retains its two public arguments.
     *
     * @param class-string $event
     */
    private function assertEventConstructorIsUnchanged(string $event, string $assignmentArgument): void
    {
        $constructor = (new ReflectionClass($event))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertSame(
            ['model', $assignmentArgument],
            array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()),
        );
        $this->assertFalse((new ReflectionClass($event))->hasProperty('partition'));
    }
}
