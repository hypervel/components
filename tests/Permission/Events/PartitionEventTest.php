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

class PartitionEventTest extends PartitionTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.events_enabled', true);
    }

    public function testSyncDetachedPayloadsOnlyIncludeTheCurrentPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);

        $this->setPartition(self::PARTITION_B);
        $user->assignRole(PartitionedRole::create(['name' => 'member']));
        $user->givePermissionTo(PartitionedPermission::create(['name' => 'articles.edit']));

        $this->setPartition(self::PARTITION_A);
        $member = PartitionedRole::create(['name' => 'member']);
        $owner = PartitionedRole::create(['name' => 'owner']);
        $edit = PartitionedPermission::create(['name' => 'articles.edit']);
        $publish = PartitionedPermission::create(['name' => 'articles.publish']);
        $user->assignRole($member);
        $user->givePermissionTo($edit);

        Event::fake([
            PermissionAttachedEvent::class,
            PermissionDetachedEvent::class,
            RoleAttachedEvent::class,
            RoleDetachedEvent::class,
        ]);

        $user->syncRoles($owner);
        $user->syncPermissions($publish);

        Event::assertDispatched(RoleDetachedEvent::class, function (RoleDetachedEvent $event) use ($user, $member): bool {
            return $event->model->is($user)
                && $event->rolesOrIds === [$member->getKey()];
        });
        Event::assertDispatched(RoleAttachedEvent::class, function (RoleAttachedEvent $event) use ($user, $owner): bool {
            return $event->model->is($user)
                && $event->rolesOrIds === [$owner->getKey()];
        });
        Event::assertDispatched(PermissionDetachedEvent::class, function (PermissionDetachedEvent $event) use ($user, $edit): bool {
            return $event->model->is($user)
                && $event->permissionsOrIds->modelKeys() === [$edit->getKey()];
        });
        Event::assertDispatched(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use ($user, $publish): bool {
            return $event->model->is($user)
                && $event->permissionsOrIds === [$publish->getKey()];
        });
    }
}
