<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\Integration\Database\Laravel\EloquentPivotWithoutTimestampTest as App;

// Load the fixtures file which defines models and migrations
require_once __DIR__ . '/EloquentPivotWithoutTimestampTest.fixtures.php';

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('auth.providers.users.model', App\User::class)]
#[WithMigration]
class EloquentPivotWithoutTimestampTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        App\migrate();
    }

    public function testAttachingModelWithoutTimestamps(): void
    {
        $now = $this->freezeSecond();

        $user = App\User::factory()->create();
        $role = App\Role::factory()->create();

        $user->roles()->attach($role->getKey(), ['notes' => 'Laravel']);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
            'notes' => 'Laravel',
            'created_at' => $now,
        ]);
    }
}
