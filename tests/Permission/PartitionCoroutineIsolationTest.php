<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\Fixtures\PartitionContext;

use function Hypervel\Coroutine\parallel;

class PartitionCoroutineIsolationTest extends PartitionTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.enable_wildcard_permission', true);
    }

    public function testAuthorizationStateIsIsolatedAcrossConcurrentPartitions(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.*']);
        $roleA = PartitionedRole::create(['name' => 'owner']);
        $roleA->givePermissionTo($permissionA);
        $user->assignRole($roleA);

        $this->setPartition(self::PARTITION_B);
        $permissionB = PartitionedPermission::create(['name' => 'articles.*']);
        $roleB = PartitionedRole::create(['name' => 'viewer']);
        $user->assignRole($roleB);
        $user->denyPermissionTo($permissionB);

        $userKey = $user->getKey();

        [$partitionA, $partitionB] = parallel([
            function () use ($userKey): array {
                PartitionContext::set(self::PARTITION_A);
                $user = GlobalPartitionUser::query()
                    ->with(['roles.permissions', 'permissions'])
                    ->findOrFail($userKey);
                $permission = PartitionedPermission::findByName('articles.*');
                usleep(5000);

                return [
                    'partition' => PartitionContext::get(),
                    'owner' => $user->hasRole('owner'),
                    'viewer' => $user->hasRole('viewer'),
                    'allowed' => $user->hasPermissionTo('articles.edit'),
                    'denied' => $user->hasDeniedPermission($permission),
                    'roles' => $user->roles->pluck('name')->all(),
                ];
            },
            function () use ($userKey): array {
                PartitionContext::set(self::PARTITION_B);
                $user = GlobalPartitionUser::query()
                    ->with(['roles.permissions', 'permissions'])
                    ->findOrFail($userKey);
                $permission = PartitionedPermission::findByName('articles.*');
                usleep(5000);

                return [
                    'partition' => PartitionContext::get(),
                    'owner' => $user->hasRole('owner'),
                    'viewer' => $user->hasRole('viewer'),
                    'allowed' => $user->hasPermissionTo('articles.edit'),
                    'denied' => $user->hasDeniedPermission($permission),
                    'roles' => $user->roles->pluck('name')->all(),
                ];
            },
        ]);

        $this->assertSame([
            'partition' => self::PARTITION_A,
            'owner' => true,
            'viewer' => false,
            'allowed' => true,
            'denied' => false,
            'roles' => ['owner'],
        ], $partitionA);
        $this->assertSame([
            'partition' => self::PARTITION_B,
            'owner' => false,
            'viewer' => true,
            'allowed' => false,
            'denied' => true,
            'roles' => ['viewer'],
        ], $partitionB);
        $this->assertSame(self::PARTITION_B, PartitionContext::get());
    }
}
