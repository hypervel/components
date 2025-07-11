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
        config(['sanctum.expiration' => 60]);

        // Create tokens with different expiration times
        $token1 = PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_1',
            'token' => hash('sha256', 'test_1'),
            'created_at' => Carbon::now()->subMinutes(181),
        ]);

        $token2 = PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_2',
            'token' => hash('sha256', 'test_2'),
            'created_at' => Carbon::now()->subMinutes(179),
        ]);

        $token3 = PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_3',
            'token' => hash('sha256', 'test_3'),
            'created_at' => Carbon::now()->subMinutes(121),
        ]);

        $this->artisan('sanctum:prune-expired', ['--hours' => 2])
            ->expectsOutputToContain('Tokens expired for more than [2 hours] pruned successfully.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Test_1']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_2']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_3']);
    }

    public function testCantDeleteExpiredTokensWithNullExpiration(): void
    {
        config(['sanctum.expiration' => null]);

        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'created_at' => Carbon::now()->subMinutes(70),
        ]);

        $this->artisan('sanctum:prune-expired', ['--hours' => 2])
            ->expectsOutputToContain('Expiration value not specified in configuration file.')
            ->assertSuccessful();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test']);
    }

    public function testCanDeleteExpiredTokensWithExpiresAtExpiration(): void
    {
        config(['sanctum.expiration' => 60]);

        $token1 = PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_1',
            'token' => hash('sha256', 'test_1'),
            'expires_at' => Carbon::now()->subMinutes(121),
        ]);

        $token2 = PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_2',
            'token' => hash('sha256', 'test_2'),
            'expires_at' => Carbon::now()->subMinutes(119),
        ]);

        $token3 = PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_3',
            'token' => hash('sha256', 'test_3'),
            'expires_at' => null,
        ]);

        $this->artisan('sanctum:prune-expired', ['--hours' => 2])
            ->expectsOutputToContain('Tokens expired for more than [2 hours] pruned successfully.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Test_1']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_2']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_3']);
    }
}
