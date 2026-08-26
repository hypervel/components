<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Jwt\JwtServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;

class JwtSecretCommandTest extends TestCase
{
    private string $environmentPath;

    private Filesystem $filesystem;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->environmentPath = ParallelTesting::tempDir('JwtSecretCommandTest');

        $this->filesystem->deleteDirectory($this->environmentPath);
        $this->filesystem->ensureDirectoryExists($this->environmentPath);
        $this->app->useEnvironmentPath($this->environmentPath);
        file_put_contents($this->app->environmentFilePath(), "APP_ENV=testing\n");
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->environmentPath);

        parent::tearDown();
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            JwtServiceProvider::class,
        ];
    }

    public function testShowPrintsSecretWithoutWritingEnvironmentFile(): void
    {
        $environmentFile = $this->app->environmentFilePath();
        $originalContents = file_get_contents($environmentFile);

        $this->artisan('jwt:secret', ['--show' => true])
            ->assertSuccessful();

        $this->assertSame($originalContents, file_get_contents($environmentFile));
    }

    public function testForceWritesSecretWithoutAddingAlgorithm(): void
    {
        $environmentFile = $this->app->environmentFilePath();

        $this->artisan('jwt:secret', ['--force' => true])
            ->assertSuccessful();

        $contents = file_get_contents($environmentFile);

        $this->assertMatchesRegularExpression('/^JWT_SECRET=.{64}$/m', $contents);
        $this->assertStringNotContainsString('JWT_ALGO=', $contents);
    }

    #[DataProvider('algorithmProvider')]
    public function testForcePreservesConfiguredAlgorithm(string $algorithm): void
    {
        $environmentFile = $this->app->environmentFilePath();
        file_put_contents($environmentFile, "JWT_SECRET=existing-secret\nJWT_ALGO={$algorithm}\n");

        $this->artisan('jwt:secret', ['--force' => true])
            ->assertSuccessful();

        $contents = file_get_contents($environmentFile);

        $this->assertStringNotContainsString('JWT_SECRET=existing-secret', $contents);
        $this->assertMatchesRegularExpression('/^JWT_SECRET=.{64}$/m', $contents);
        $this->assertStringContainsString("JWT_ALGO={$algorithm}", $contents);
    }

    public static function algorithmProvider(): array
    {
        return [
            'symmetric' => ['HS256'],
            'RSA' => ['RS256'],
            'elliptic curve' => ['ES256'],
        ];
    }

    public function testAlwaysNoSkipsExistingSecret(): void
    {
        $environmentFile = $this->app->environmentFilePath();

        file_put_contents($environmentFile, "JWT_SECRET=existing-secret\n");

        $this->artisan('jwt:secret', ['--always-no' => true])
            ->expectsOutputToContain('JWT secret already exists. Skipping...')
            ->assertSuccessful();

        $this->assertSame("JWT_SECRET=existing-secret\n", file_get_contents($environmentFile));
    }

    public function testConfirmationNoSkipsExistingSecret(): void
    {
        $environmentFile = $this->app->environmentFilePath();

        file_put_contents($environmentFile, "JWT_SECRET=existing-secret\n");

        $this->artisan('jwt:secret')
            ->expectsConfirmation('This will invalidate all existing tokens. Are you sure you want to override the JWT secret?', 'no')
            ->expectsOutputToContain('No changes were made to your JWT secret.')
            ->assertSuccessful();

        $this->assertSame("JWT_SECRET=existing-secret\n", file_get_contents($environmentFile));
    }

    public function testConfirmationYesOverwritesExistingSecret(): void
    {
        $environmentFile = $this->app->environmentFilePath();

        file_put_contents($environmentFile, "JWT_SECRET=existing-secret\nJWT_ALGO=RS256\n");

        $this->artisan('jwt:secret')
            ->expectsConfirmation('This will invalidate all existing tokens. Are you sure you want to override the JWT secret?', 'yes')
            ->assertSuccessful();

        $contents = file_get_contents($environmentFile);

        $this->assertStringNotContainsString('JWT_SECRET=existing-secret', $contents);
        $this->assertMatchesRegularExpression('/^JWT_SECRET=.{64}$/m', $contents);
        $this->assertStringContainsString('JWT_ALGO=RS256', $contents);
    }

    public function testFailsWhenEnvironmentFileIsMissing(): void
    {
        $environmentFile = $this->app->environmentFilePath();

        if (file_exists($environmentFile)) {
            unlink($environmentFile);
        }

        $this->artisan('jwt:secret', ['--force' => true])
            ->expectsOutputToContain("The file [{$environmentFile}] does not exist.")
            ->assertExitCode(Command::FAILURE);
    }
}
