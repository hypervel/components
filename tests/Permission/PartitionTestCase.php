<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionWorkspaceTeam;
use Hypervel\Tests\Permission\Fixtures\PartitionContext;

abstract class PartitionTestCase extends TestCase
{
    protected const string PARTITION_A = '00000000-0000-4000-8000-00000000000a';

    protected const string PARTITION_B = '00000000-0000-4000-8000-00000000000b';

    protected bool $partitionTeams = false;

    /**
     * Configure the partitioned Permission test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        PermissionRegistrar::resolvePartitionUsing(
            'workspace_id',
            static fn (): int|string|null => PartitionContext::get(),
        );

        $app->make('config')->set([
            'permission.models.permission' => PartitionedPermission::class,
            'permission.models.role' => PartitionedRole::class,
            'permission.models.default_model' => GlobalPartitionUser::class,
            'permission.teams' => $this->partitionTeams,
            'permission.models.team' => $this->partitionTeams ? PartitionWorkspaceTeam::class : null,
            'auth.providers.users.model' => GlobalPartitionUser::class,
        ]);
    }

    /**
     * Create the application-owned partitioned schema.
     */
    protected function afterRefreshingDatabase(): void
    {
        $this->dropStockPermissionTables();
        $this->createPartitionOwnerTables();
        $this->createPartitionSubjectTables();
        $this->createPartitionPermissionTables();

        PartitionContext::set(self::PARTITION_A);
        $this->flushPermissionState();
    }

    /**
     * Set the active permission partition.
     */
    protected function setPartition(int|string $partition): void
    {
        PartitionContext::set($partition);
    }

    /**
     * Drop the stock unpartitioned Permission tables.
     */
    private function dropStockPermissionTables(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }

    /**
     * Create generic partition owner and optional team tables.
     */
    private function createPartitionOwnerTables(): void
    {
        Schema::create('permission_workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        $connection = Schema::getConnection();
        $connection->table('permission_workspaces')->insert([
            ['id' => self::PARTITION_A, 'name' => 'Workspace A'],
            ['id' => self::PARTITION_B, 'name' => 'Workspace B'],
        ]);

        if (! $this->partitionTeams) {
            return;
        }

        Schema::create('partition_workspace_teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name');
            $table->unique(['workspace_id', 'id']);
        });
    }

    /**
     * Create globally identified local and global subject tables.
     */
    private function createPartitionSubjectTables(): void
    {
        Schema::create('partitioned_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('email');
            $table->index(['workspace_id', 'id']);
        });

        Schema::create('global_partition_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->softDeletes();
        });
    }

    /**
     * Create all five partitioned authorization tables.
     */
    private function createPartitionPermissionTables(): void
    {
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

            if ($this->partitionTeams) {
                $table->uuid('team_test_id')->nullable();
                $table->unique(['workspace_id', 'team_test_id', 'name', 'guard_name']);
            } else {
                $table->unique(['workspace_id', 'name', 'guard_name']);
            }

            $table->timestamps();
            $table->unique(['workspace_id', 'id']);
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

            $primary = ['workspace_id'];

            if ($this->partitionTeams) {
                $table->uuid('team_test_id')->nullable();
                $primary[] = 'team_test_id';
            }

            $primary = [...$primary, 'role_test_id', 'model_test_id', 'model_type'];
            $table->primary($primary);
            $table->index(
                ['workspace_id', 'model_type', 'model_test_id'],
                'model_has_roles_partition_subject_index',
            );
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

            $primary = ['workspace_id'];

            if ($this->partitionTeams) {
                $table->uuid('team_test_id')->nullable();
                $primary[] = 'team_test_id';
            }

            $primary = [...$primary, 'permission_test_id', 'model_test_id', 'model_type'];
            $table->primary($primary);
            $table->index(
                ['workspace_id', 'model_type', 'model_test_id'],
                'model_has_permissions_partition_subject_index',
            );
            $table->foreign(['workspace_id', 'permission_test_id'])
                ->references(['workspace_id', 'id'])
                ->on('permissions')
                ->cascadeOnDelete();
        });
    }
}
