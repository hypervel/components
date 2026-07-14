<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Permission\Exceptions\PermissionAlreadyExists;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\PermissionServiceProvider;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\Fixtures\PartitionContext;

class PermissionPartitionTest extends DatabaseTestCase
{
    private const string PARTITION_A = '00000000-0000-4000-8000-00000000000a';

    private const string PARTITION_B = '00000000-0000-4000-8000-00000000000b';

    /**
     * Get package providers.
     */
    protected function getPackageProviders(mixed $app): array
    {
        return [PermissionServiceProvider::class];
    }

    /**
     * Configure the cross-database partition test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        if ($this->driver === 'sqlite') {
            $connection = $config->string('database.default');
            $config->set("database.connections.{$connection}.foreign_key_constraints", true);
        }

        PermissionRegistrar::resolvePartitionUsing(
            'workspace_id',
            static fn (): int|string|null => PartitionContext::get(),
        );

        $config->set([
            'permission.models.permission' => PartitionedPermission::class,
            'permission.models.role' => PartitionedRole::class,
            'permission.models.default_model' => GlobalPartitionUser::class,
            'permission.column_names.model_morph_key' => 'model_test_id',
            'permission.column_names.role_pivot_key' => 'role_test_id',
            'permission.column_names.permission_pivot_key' => 'permission_test_id',
            'permission.cache.store' => 'array',
            'auth.guards.web' => ['driver' => 'session', 'provider' => 'users'],
            'auth.providers.users' => ['driver' => 'eloquent', 'model' => GlobalPartitionUser::class],
            'cache.default' => 'array',
            'cache.stores.array' => ['driver' => 'array'],
        ]);
    }

    /**
     * Create the application-owned partitioned authorization schema.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('permission_workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        Schema::create('global_partition_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email');
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'name', 'guard_name']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('permission_test_id');
            $table->uuid('role_test_id');
            $table->boolean('is_forbidden')->default(false);
            $table->primary(['workspace_id', 'permission_test_id', 'role_test_id']);
            $table->foreign(['workspace_id', 'permission_test_id'])
                ->references(['workspace_id', 'id'])
                ->on('permissions')
                ->cascadeOnDelete();
            $table->foreign(['workspace_id', 'role_test_id'])
                ->references(['workspace_id', 'id'])
                ->on('roles')
                ->cascadeOnDelete();
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('role_test_id');
            $table->string('model_type');
            $table->uuid('model_test_id');
            $table->primary(['workspace_id', 'role_test_id', 'model_test_id', 'model_type']);
            $table->index(['workspace_id', 'model_type', 'model_test_id']);
            $table->foreign(['workspace_id', 'role_test_id'])
                ->references(['workspace_id', 'id'])
                ->on('roles')
                ->cascadeOnDelete();
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->uuid('workspace_id');
            $table->uuid('permission_test_id');
            $table->string('model_type');
            $table->uuid('model_test_id');
            $table->boolean('is_forbidden')->default(false);
            $table->primary(['workspace_id', 'permission_test_id', 'model_test_id', 'model_type']);
            $table->index(['workspace_id', 'model_type', 'model_test_id']);
            $table->foreign(['workspace_id', 'permission_test_id'])
                ->references(['workspace_id', 'id'])
                ->on('permissions')
                ->cascadeOnDelete();
        });

        DB::table('permission_workspaces')->insert([
            ['id' => self::PARTITION_A, 'name' => 'Workspace A'],
            ['id' => self::PARTITION_B, 'name' => 'Workspace B'],
        ]);
    }

    /**
     * Establish the ambient partition inside each test coroutine.
     */
    protected function setUpInCoroutine(): void
    {
        PartitionContext::set(self::PARTITION_A);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function testCompositeSchemaAndPartitionLeadingIndexesArePortable(): void
    {
        $roleIndexes = Schema::getIndexes('roles');
        $permissionIndexes = Schema::getIndexes('permissions');
        $modelRoleIndexes = Schema::getIndexes('model_has_roles');
        $modelPermissionIndexes = Schema::getIndexes('model_has_permissions');

        $this->assertTrue(collect($roleIndexes)->contains(
            fn (array $index): bool => $index['columns'] === ['workspace_id', 'id'] && $index['unique'],
        ));
        $this->assertTrue(collect($permissionIndexes)->contains(
            fn (array $index): bool => $index['columns'] === ['workspace_id', 'id'] && $index['unique'],
        ));
        $this->assertTrue(collect($modelRoleIndexes)->contains(
            fn (array $index): bool => $index['columns'] === ['workspace_id', 'model_type', 'model_test_id'],
        ));
        $this->assertTrue(collect($modelPermissionIndexes)->contains(
            fn (array $index): bool => $index['columns'] === ['workspace_id', 'model_type', 'model_test_id'],
        ));
    }

    public function testSameNameAndGuardAreUniqueOnlyInsideAPartition(): void
    {
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);

        PartitionContext::set(self::PARTITION_B);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);

        $this->assertNotSame($permissionA->getKey(), $permissionB->getKey());
        $this->assertSame(self::PARTITION_B, $permissionB->getAttribute('workspace_id'));

        $this->expectException(PermissionAlreadyExists::class);

        PartitionedPermission::create(['name' => 'articles.edit']);
    }

    public function testCompositeForeignKeysRejectCrossPartitionEdges(): void
    {
        $roleA = PartitionedRole::create(['name' => 'editor']);

        PartitionContext::set(self::PARTITION_B);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);

        $this->expectException(QueryException::class);

        DB::table('role_has_permissions')->insert([
            'workspace_id' => self::PARTITION_A,
            'permission_test_id' => $permissionB->getKey(),
            'role_test_id' => $roleA->getKey(),
            'is_forbidden' => false,
        ]);
    }

    public function testUuidValuesRoundTripThroughPackageRelations(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'editor']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $roleA->givePermissionTo($permissionA);
        $user->assignRole($roleA);

        $this->assertTrue($user->hasRole($roleA));
        $this->assertTrue($user->hasPermissionTo($permissionA));

        PartitionContext::set(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'editor']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $roleB->givePermissionTo($permissionB);
        $user->assignRole($roleB);

        $this->assertTrue($user->hasRole($roleB));
        $this->assertTrue($user->hasPermissionTo($permissionB));

        PartitionContext::set(self::PARTITION_A);

        $this->assertSame([$roleA->getKey()], $user->roles()->pluck('roles.id')->all());
        $this->assertSame([$permissionA->getKey()], $roleA->permissions()->pluck('permissions.id')->all());
    }

    public function testPermissionEffectSynchronizationReportsOnlyRealChanges(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'effects@example.com']);
        $permission = PartitionedPermission::create(['name' => 'articles.publish']);

        $this->assertSame([
            'attached' => [$permission->getKey()],
            'detached' => [],
            'updated' => [],
        ], $user->syncPermissionsWithForbidden([$permission]));

        $this->assertSame([
            'attached' => [],
            'detached' => [],
            'updated' => [$permission->getKey()],
        ], $user->syncPermissionsWithForbidden([], [$permission]));
        $this->assertTrue($user->hasForbiddenPermission($permission));

        $this->assertSame([
            'attached' => [],
            'detached' => [],
            'updated' => [],
        ], $user->syncPermissionsWithForbidden([], [$permission]));

        $this->assertSame([
            'attached' => [],
            'detached' => [],
            'updated' => [$permission->getKey()],
        ], $user->syncPermissionsWithForbidden([$permission]));
        $this->assertFalse($user->hasForbiddenPermission($permission));

        $this->assertSame([
            'attached' => [],
            'detached' => [],
            'updated' => [$permission->getKey()],
        ], $user->syncPermissionsWithForbidden([$permission], [$permission]));
        $this->assertTrue($user->hasForbiddenPermission($permission));

        $secondPermission = PartitionedPermission::create(['name' => 'articles.archive']);
        $user->givePermissionTo($secondPermission);
        $expectedDetached = [$permission->getKey(), $secondPermission->getKey()];
        sort($expectedDetached, SORT_STRING);

        $this->assertSame([
            'attached' => [],
            'detached' => $expectedDetached,
            'updated' => [],
        ], $user->syncPermissionsWithForbidden());
    }
}
