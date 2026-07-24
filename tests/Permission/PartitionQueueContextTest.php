<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Permission\Exceptions\PermissionPartitionNotResolved;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\SerializesModels;
use Hypervel\Queue\SyncQueue;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\Fixtures\PartitionContext;
use Mockery as m;

class PartitionQueueContextTest extends PartitionTestCase
{
    public function testQueuePayloadHydratesPartitionBeforeRestoringModelsAndCheckingPermission(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'editor']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        $queue = $this->createSyncQueue();
        $payload = $queue->testCreatePayload(new PartitionPermissionJob($user, $role, $permission));

        $this->assertSame(
            self::PARTITION_A,
            unserialize($payload['illuminate:log:context']['data'][PartitionContext::KEY]),
        );

        CoroutineContext::flush();

        $job = m::mock(JobContract::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $this->app->make('events')->dispatch(new JobProcessing('sync', $job));

        $this->assertSame(self::PARTITION_A, PartitionContext::get());

        $restored = unserialize($payload['data']['command']);

        $this->assertInstanceOf(PartitionPermissionJob::class, $restored);
        $this->assertSame($user->getKey(), $restored->user->getKey());
        $this->assertSame($role->getKey(), $restored->role->getKey());
        $this->assertSame($permission->getKey(), $restored->permission->getKey());
        $this->assertTrue($restored->authorized());
    }

    public function testQueuedModelRestorationFailsClosedWithoutPartitionContext(): void
    {
        $role = PartitionedRole::create(['name' => 'editor']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $serialized = serialize(new PartitionPermissionJob(
            GlobalPartitionUser::create(['email' => 'global@example.com']),
            $role,
            $permission,
        ));
        PartitionContext::forget();

        $this->expectException(PermissionPartitionNotResolved::class);

        unserialize($serialized);
    }

    public function testQueuedModelRestorationCannotLoadRoleOrPermissionFromAnotherPartition(): void
    {
        $serialized = serialize(new PartitionPermissionJob(
            GlobalPartitionUser::create(['email' => 'global@example.com']),
            PartitionedRole::create(['name' => 'editor']),
            PartitionedPermission::create(['name' => 'articles.edit']),
        ));
        $this->setPartition(self::PARTITION_B);

        $this->expectException(ModelNotFoundException::class);

        unserialize($serialized);
    }

    /**
     * Create a sync queue that exposes payload construction for restoration-order tests.
     */
    private function createSyncQueue(): PartitionTestableSyncQueue
    {
        $queue = new PartitionTestableSyncQueue;
        $queue->setContainer($this->app);
        $queue->setConnectionName('sync');

        return $queue;
    }
}

class PartitionTestableSyncQueue extends SyncQueue
{
    /**
     * Create a queue payload without executing it.
     */
    public function testCreatePayload(object $job): array
    {
        return $this->createPayloadArray($job, null);
    }
}

class PartitionPermissionJob implements ShouldQueue
{
    use SerializesModels;

    public function __construct(
        public GlobalPartitionUser $user,
        public PartitionedRole $role,
        public PartitionedPermission $permission,
    ) {
    }

    /**
     * Check the job's restored authorization state.
     */
    public function authorized(): bool
    {
        return $this->user->hasRole($this->role)
            && $this->user->hasPermissionTo($this->permission);
    }
}
