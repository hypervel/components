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
            ->doesntExpectOutputToContain('Restart the server')
            ->assertSuccessful();

        $this->assertSame($originalContents, file_get_contents($environmentFile));
    }

    public function testForceWritesSecretWithoutAddingAlgorithm(): void
    {
        $environmentFile = $this->app->environmentFilePath();

        $this->artisan('jwt:secret', ['--force' => true])
            ->expectsOutputToContain(
                'Restart the server and every other long-running application process, including queue workers and custom server processes, '
                . 'before issuing tokens with the new secret. The [php artisan server:reload] command only replaces server workers and is not sufficient.'
            )
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

    #[DataProvider('existingSecretProvider')]
    public function testAlwaysNoSkipsExistingSecret(string $assignment, bool $force): void
    {
        $environmentFile = $this->app->environmentFilePath();

        file_put_contents($environmentFile, "{$assignment}\n");

        $this->artisan('jwt:secret', ['--always-no' => true, '--force' => $force])
            ->expectsOutputToContain('JWT secret already exists. Skipping...')
            ->doesntExpectOutputToContain('Restart the server')
            ->assertSuccessful();

        $this->assertSame("{$assignment}\n", file_get_contents($environmentFile));
    }

    /**
     * Provide existing secret assignments and whether to pass --force.
     *
     * @return array<string, array{string, bool}>
     */
    public static function existingSecretProvider(): array
    {
        return [
            'plain' => ['JWT_SECRET=existing-secret', false],
            'plain with force' => ['JWT_SECRET=existing-secret', true],
            'spaced with force' => ['JWT_SECRET = existing-secret', true],
            'exported with force' => ['export JWT_SECRET=existing-secret', true],
            'reference with force' => ['JWT_SECRET="${DEPLOYED_JWT_SECRET}"', true],
        ];
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

        file_put_contents($environmentFile, "JWT_ALGO=RS256\nexport JWT_SECRET = existing-secret\n");

        $this->artisan('jwt:secret')
            ->expectsConfirmation('This will invalidate all existing tokens. Are you sure you want to override the JWT secret?', 'yes')
            ->assertSuccessful();

        $contents = file_get_contents($environmentFile);

        $this->assertMatchesRegularExpression('/\AJWT_ALGO=RS256\nexport JWT_SECRET=.{64}\n\z/', $contents);
    }

    public function testEmptySecretIsReplacedWithoutConfirmation(): void
    {
        $environmentFile = $this->app->environmentFilePath();

        file_put_contents($environmentFile, "JWT_SECRET=\n");

        $this->artisan('jwt:secret')
            ->assertSuccessful();

        $this->assertMatchesRegularExpression('/\AJWT_SECRET=.{64}\n\z/', file_get_contents($environmentFile));
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
