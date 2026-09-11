<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Database\Fixtures\EloquentPivotWithoutTimestamp\Role;
use Hypervel\Tests\Integration\Database\Fixtures\EloquentPivotWithoutTimestamp\User;

#[WithConfig('auth.providers.users.model', User::class)]
#[WithMigration]
class EloquentPivotWithoutTimestampTest extends DatabaseTestCase
{
    /**
     * Create the role and pivot tables after refreshing the database.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            $table->text('notes');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function testAttachingModelWithoutTimestamps(): void
    {
        $now = $this->freezeSecond();

        $user = User::factory()->create();
        $role = Role::factory()->create();

        $user->roles()->attach($role->getKey(), ['notes' => 'Hypervel']);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
            'notes' => 'Hypervel',
            'created_at' => $now,
        ]);
    }
}
