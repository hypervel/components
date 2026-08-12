<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\Console\Commands\PruneExpired;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PruneExpiredTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SanctumServiceProvider::class,
        ];
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    public function testCommandCanBeResolvedFromCompleteCommandMap(): void
    {
        $command = $this->app->make(Kernel::class)->all()['sanctum:prune-expired'];

        $this->assertInstanceOf(PruneExpired::class, $command);
        $this->assertSame('24', $command->getDefinition()->getOption('hours')->getDefault());
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
            'created_at' => CarbonImmutable::now()->subMinutes(181),
        ]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_2',
            'token' => hash('sha256', 'test_2'),
            'created_at' => CarbonImmutable::now()->subMinutes(179),
        ]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_3',
            'token' => hash('sha256', 'test_3'),
            'created_at' => CarbonImmutable::now()->subMinutes(121),
        ]);

        $this->artisan('sanctum:prune-expired --hours=2')
            ->expectsOutputToContain('Tokens expired for more than [2 hours] pruned successfully.');

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
            'created_at' => CarbonImmutable::now()->subMinutes(70),
        ]);

        $this->artisan('sanctum:prune-expired --hours=2')
            ->expectsOutputToContain('Expiration value not specified in configuration file.');

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
            'expires_at' => CarbonImmutable::now()->subMinutes(121),
        ]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_2',
            'token' => hash('sha256', 'test_2'),
            'expires_at' => CarbonImmutable::now()->subMinutes(119),
        ]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Test_3',
            'token' => hash('sha256', 'test_3'),
            'expires_at' => null,
        ]);

        $this->artisan('sanctum:prune-expired --hours=2')
            ->expectsOutputToContain('Tokens expired for more than [2 hours] pruned successfully.');

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Test_1']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_2']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Test_3']);
    }

    #[DataProvider('invalidHoursProvider')]
    public function testInvalidHoursFailBeforeQuerying(string $hours): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->artisan("sanctum:prune-expired --hours={$hours}")
            ->expectsOutput('The --hours option must be a non-negative integer.')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame([], DB::getQueryLog());
    }

    public static function invalidHoursProvider(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'decimal' => ['1.5'];
        yield 'nonnumeric' => ['invalid'];
    }

    public function testZeroHoursIsAccepted(): void
    {
        $this->app->make('config')->set(['sanctum.expiration' => null]);

        PersonalAccessToken::forceCreate([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => 1,
            'name' => 'Expired Now',
            'token' => hash('sha256', 'expired-now'),
            'expires_at' => CarbonImmutable::now()->subSecond(),
        ]);

        $this->artisan('sanctum:prune-expired --hours=0')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Expired Now']);
    }
}
