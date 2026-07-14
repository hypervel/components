<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Commands;

use Hypervel\Permission\Exceptions\PermissionPartitionNotResolved;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\Fixtures\PartitionContext;
use Hypervel\Tests\Permission\PartitionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PartitionCommandTest extends PartitionTestCase
{
    public function testCreateAndShowCommandsOperateOnlyInTheAmbientPartition(): void
    {
        Artisan::call('permission:create-role', [
            'name' => 'workspace-a-role',
            'permissions' => 'workspace-a-permission',
        ]);
        Artisan::call('permission:create-permission', ['name' => 'shared-permission']);

        $this->assertTrue(PartitionedRole::query()->where('name', 'workspace-a-role')->exists());
        $this->assertTrue(PartitionedPermission::query()->where('name', 'workspace-a-permission')->exists());

        $this->setPartition(self::PARTITION_B);

        Artisan::call('permission:create-role', [
            'name' => 'workspace-b-role',
            'permissions' => 'workspace-b-permission',
        ]);
        Artisan::call('permission:create-permission', ['name' => 'shared-permission']);
        Artisan::call('permission:show');
        $output = Artisan::output();

        $this->assertStringContainsString('workspace-b-role', $output);
        $this->assertStringContainsString('workspace-b-permission', $output);
        $this->assertStringNotContainsString('workspace-a-role', $output);
        $this->assertStringNotContainsString('workspace-a-permission', $output);

        $this->setPartition(self::PARTITION_A);

        $this->assertTrue(PartitionedRole::query()->where('name', 'workspace-a-role')->exists());
        $this->assertFalse(PartitionedRole::query()->where('name', 'workspace-b-role')->exists());
        $this->assertSame(1, PartitionedPermission::query()->where('name', 'shared-permission')->count());
    }

    public function testAssignRoleCommandUsesTheAmbientPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);

        Artisan::call('permission:assign-role', [
            'name' => 'member',
            'userId' => $user->getKey(),
            'guard' => 'web',
            'userModelNamespace' => GlobalPartitionUser::class,
        ]);

        $this->assertTrue($user->hasRole($roleA));

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);

        Artisan::call('permission:assign-role', [
            'name' => 'member',
            'userId' => $user->getKey(),
            'guard' => 'web',
            'userModelNamespace' => GlobalPartitionUser::class,
        ]);

        $this->assertTrue($user->hasRole($roleB));

        $this->setPartition(self::PARTITION_A);

        $this->assertTrue($user->hasRole($roleA));
    }

    public function testCacheResetClearsOnlyTheAmbientPartition(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        PartitionedPermission::create(['name' => 'workspace-a-permission']);
        $registrar->getPermissions();
        $keyA = $registrar->getCacheKey();

        $this->setPartition(self::PARTITION_B);
        PartitionedPermission::create(['name' => 'workspace-b-permission']);
        $registrar->getPermissions();
        $keyB = $registrar->getCacheKey();

        $this->assertTrue($registrar->getCacheRepository()->has($keyA));
        $this->assertTrue($registrar->getCacheRepository()->has($keyB));

        Artisan::call('permission:cache-reset');

        $this->assertTrue($registrar->getCacheRepository()->has($keyA));
        $this->assertFalse($registrar->getCacheRepository()->has($keyB));
    }

    #[DataProvider('partitionRequiredCommands')]
    public function testDataCommandsFailClosedWithoutPartitionContext(string $command, array $arguments): void
    {
        PartitionContext::forget();

        $this->expectException(PermissionPartitionNotResolved::class);

        Artisan::call($command, $arguments);
    }

    public static function partitionRequiredCommands(): array
    {
        return [
            'create role' => ['permission:create-role', ['name' => 'member']],
            'create permission' => ['permission:create-permission', ['name' => 'articles.edit']],
            'show' => ['permission:show', []],
            'cache reset' => ['permission:cache-reset', []],
        ];
    }

    public function testAssignRoleCommandFailsClosedWithoutPartitionContext(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        PartitionContext::forget();

        $this->expectException(PermissionPartitionNotResolved::class);

        Artisan::call('permission:assign-role', [
            'name' => 'member',
            'userId' => $user->getKey(),
            'guard' => 'web',
            'userModelNamespace' => GlobalPartitionUser::class,
        ]);
    }

    public function testCommandsDoNotInventAPartitionOption(): void
    {
        $commands = Artisan::all();

        foreach ([
            'permission:create-role',
            'permission:create-permission',
            'permission:assign-role',
            'permission:show',
            'permission:cache-reset',
            'permission:setup-teams',
        ] as $name) {
            $this->assertArrayHasKey($name, $commands);
            $this->assertFalse($commands[$name]->getDefinition()->hasOption('partition'));
        }
    }
}
