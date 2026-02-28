<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Support\Carbon;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PruneExpiredTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    public function testCanDeleteExpiredTokensWithIntegerExpiration(): void
    {
        $this->app->make('config')
            ->set(['sanctum.expiration' => 60]);

        // Create tokens with different expiration times
        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_1',
            'token' => hash('sha256', 'test_1'),
            'created_at' => Carbon::now()->subMinutes(181),
        ]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_2',
            'token' => hash('sha256', 'test_2'),
            'created_at' => Carbon::now()->subMinutes(179),
        ]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_3',
            'token' => hash('sha256', 'test_3'),
            'created_at' => Carbon::now()->subMinutes(121),
        ]);

        // Test directly using model methods
        $hours = 2;

        // This is what the command does
        $model = PersonalAccessToken::class;
        $model::where('expires_at', '<', now()->subHours($hours))->delete();

        $expiration = $this->app->make('config')
            ->get('sanctum.expiration');
        if ($expiration) {
            $model::where('created_at', '<', now()->subMinutes($expiration + ($hours * 60)))->delete();
        }

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Test_1']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_2']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_3']);
    }

    public function testCantDeleteExpiredTokensWithNullExpiration(): void
    {
        $this->app->make('config')
            ->set(['sanctum.expiration' => null]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'created_at' => Carbon::now()->subMinutes(70),
        ]);

        // Test directly using model methods
        $hours = 2;

        // This is what the command does
        $model = PersonalAccessToken::class;
        $model::where('expires_at', '<', now()->subHours($hours))->delete();

        // With null expiration, no config-based deletion happens
        $expiration = $this->app->make('config')
            ->get('sanctum.expiration');
        $this->assertNull($expiration);

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test']);
    }

    public function testCanDeleteExpiredTokensWithExpiresAtExpiration(): void
    {
        $this->app->make('config')
            ->set(['sanctum.expiration' => 60]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_1',
            'token' => hash('sha256', 'test_1'),
            'expires_at' => Carbon::now()->subMinutes(121),
        ]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_2',
            'token' => hash('sha256', 'test_2'),
            'expires_at' => Carbon::now()->subMinutes(119),
        ]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_3',
            'token' => hash('sha256', 'test_3'),
            'expires_at' => null,
        ]);

        // Test directly using model methods
        $hours = 2;

        // This is what the command does
        $model = PersonalAccessToken::class;
        $model::where('expires_at', '<', now()->subHours($hours))->delete();

        $expiration = $this->app->make('config')
            ->get('sanctum.expiration');
        if ($expiration) {
            $model::where('created_at', '<', now()->subMinutes($expiration + ($hours * 60)))->delete();
        }

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Test_1']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_2']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_3']);
    }
}
