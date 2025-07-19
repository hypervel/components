<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\Console\Commands\PruneExpired;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Support\Carbon;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

    /**
     * Run the command directly without going through artisan.
     */
    protected function runCommand(array $parameters = []): string
    {
        $command = new PruneExpired();
        $command->setLaravel($this->app);

        $input = new ArrayInput($parameters);
        $output = new BufferedOutput();

        $command->run($input, $output);

        return $output->fetch();
    }

    public function testCanDeleteExpiredTokensWithIntegerExpiration(): void
    {
        config(['sanctum.expiration' => 60]);

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

        $output = $this->runCommand(['--hours' => 2]);

        $this->assertStringContainsString('Tokens expired for more than [2 hours] pruned successfully.', $output);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Test_1']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_2']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_3']);
    }

    public function testCantDeleteExpiredTokensWithNullExpiration(): void
    {
        config(['sanctum.expiration' => null]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'created_at' => Carbon::now()->subMinutes(70),
        ]);

        $output = $this->runCommand(['--hours' => 2]);

        $this->assertStringContainsString('Expiration value not specified in configuration file.', $output);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test']);
    }

    public function testCanDeleteExpiredTokensWithExpiresAtExpiration(): void
    {
        config(['sanctum.expiration' => 60]);

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

        $output = $this->runCommand(['--hours' => 2]);

        $this->assertStringContainsString('Tokens expired for more than [2 hours] pruned successfully.', $output);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Test_1']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_2']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_3']);
    }
}
