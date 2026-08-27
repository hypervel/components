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
use RuntimeException;

class JwtGenerateCertsCommandTest extends TestCase
{
    private string $environmentPath;

    private Filesystem $filesystem;

    /**
     * @var array<int, string>
     */
    private array $temporaryPaths = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->environmentPath = ParallelTesting::tempDir('JwtGenerateCertsCommandTest-env');

        $this->filesystem->deleteDirectory($this->environmentPath);
        $this->filesystem->ensureDirectoryExists($this->environmentPath);
        $this->app->useEnvironmentPath($this->environmentPath);
        file_put_contents($this->app->environmentFilePath(), "APP_ENV=testing\n");
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            $this->filesystem->deleteDirectory($path);
        }

        $this->filesystem->deleteDirectory($this->environmentPath);

        parent::tearDown();
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            JwtServiceProvider::class,
        ];
    }

    public function testGeneratesCertificatesAndWritesEnvironmentVariables(): void
    {
        $directory = $this->temporaryDirectory('generate');

        $this->artisan('jwt:generate-certs', [
            '--force' => true,
            '--algo' => 'rsa',
            '--bits' => 2048,
            '--sha' => 256,
            '--dir' => $directory,
            '--passphrase' => 'secret',
        ])
            ->expectsOutputToContain(
                'Restart the server and every other long-running application process, including queue workers and custom server processes, '
                . 'before issuing tokens with the new certificate pair. The [php artisan server:reload] command only replaces server workers and is not sufficient.'
            )
            ->assertSuccessful();

        $privateKeyPath = $directory . '/jwt-rsa-2048-private.pem';
        $publicKeyPath = $directory . '/jwt-rsa-2048-public.pem';

        $this->assertDirectoryExists($directory);
        $this->assertFileExists($privateKeyPath);
        $this->assertFileExists($publicKeyPath);
        $this->assertStringContainsString('-----BEGIN ENCRYPTED PRIVATE KEY-----', file_get_contents($privateKeyPath));
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', file_get_contents($publicKeyPath));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($privateKeyPath)), -4));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($publicKeyPath)), -4));

        $contents = file_get_contents($this->app->environmentFilePath());

        $this->assertStringContainsString('JWT_ALGO=RS256', $contents);
        $this->assertStringContainsString('JWT_PRIVATE_KEY="file://' . $privateKeyPath . '"', $contents);
        $this->assertStringContainsString('JWT_PUBLIC_KEY="file://' . $publicKeyPath . '"', $contents);
        $this->assertStringContainsString('JWT_PASSPHRASE=secret', $contents);
    }

    public function testGeneratesUnencryptedPrivateKeyWhenNoPassphraseIsConfigured(): void
    {
        $directory = $this->temporaryDirectory('no-passphrase');

        $this->artisan('jwt:generate-certs', [
            '--force' => true,
            '--algo' => 'ec',
            '--sha' => 256,
            '--dir' => $directory,
            '--curve' => 'prime256v1',
        ])->assertSuccessful();

        $privateKey = file_get_contents($directory . '/jwt-ec-prime256v1-private.pem');

        $this->assertStringContainsString('-----BEGIN PRIVATE KEY-----', $privateKey);
        $this->assertStringNotContainsString('-----BEGIN ENCRYPTED PRIVATE KEY-----', $privateKey);
    }

    public function testGeneratesEcCertificates(): void
    {
        $directory = $this->temporaryDirectory('ec');

        $this->artisan('jwt:generate-certs', [
            '--force' => true,
            '--algo' => 'ec',
            '--sha' => 256,
            '--dir' => $directory,
            '--curve' => 'prime256v1',
        ])->assertSuccessful();

        $privateKeyPath = $directory . '/jwt-ec-prime256v1-private.pem';
        $publicKeyPath = $directory . '/jwt-ec-prime256v1-public.pem';

        $this->assertFileExists($privateKeyPath);
        $this->assertFileExists($publicKeyPath);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($privateKeyPath)), -4));

        $contents = file_get_contents($this->app->environmentFilePath());

        $this->assertStringContainsString('JWT_ALGO=ES256', $contents);
        $this->assertStringContainsString('JWT_PRIVATE_KEY="file://' . $privateKeyPath . '"', $contents);
        $this->assertStringContainsString('JWT_PUBLIC_KEY="file://' . $publicKeyPath . '"', $contents);
    }

    public function testGeneratesAllEcCertificateVariantsWithMatchingCurves(): void
    {
        foreach ([256 => 'prime256v1', 384 => 'secp384r1', 512 => 'secp521r1'] as $sha => $curve) {
            $directory = $this->temporaryDirectory("ec-{$sha}");

            $this->artisan('jwt:generate-certs', [
                '--force' => true,
                '--algo' => 'ec',
                '--sha' => $sha,
                '--dir' => $directory,
                '--curve' => $curve,
            ])->assertSuccessful();

            $this->assertFileExists($directory . "/jwt-ec-{$curve}-private.pem");
            $this->assertFileExists($directory . "/jwt-ec-{$curve}-public.pem");
            $this->assertStringContainsString("JWT_ALGO=ES{$sha}", file_get_contents($this->app->environmentFilePath()));
        }
    }

    public function testRejectsMismatchedEcCurve(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ES512 requires the [secp521r1] curve.');

        $this->artisan('jwt:generate-certs', [
            '--force' => true,
            '--algo' => 'ec',
            '--sha' => 512,
            '--curve' => 'prime256v1',
            '--dir' => $this->temporaryDirectory('ec-mismatch'),
        ]);
    }

    public function testRejectsUnsupportedShaVariant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT certificate SHA variant must be 256, 384, or 512.');

        $this->artisan('jwt:generate-certs', [
            '--force' => true,
            '--algo' => 'rsa',
            '--sha' => 999,
            '--dir' => $this->temporaryDirectory('invalid-sha'),
        ]);
    }

    public function testRejectsRsaKeysBelow2048BitsBeforeGeneration(): void
    {
        $directory = $this->temporaryDirectory('weak-rsa');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT RSA certificates must use at least 2048 bits.');

        try {
            $this->artisan('jwt:generate-certs', [
                '--force' => true,
                '--algo' => 'rsa',
                '--bits' => 1024,
                '--sha' => 256,
                '--dir' => $directory,
            ]);
        } finally {
            $this->assertDirectoryDoesNotExist($directory);
        }
    }

    public function testInteractivePassphrasePreservesStringZero(): void
    {
        $directory = $this->temporaryDirectory('zero-passphrase');

        $this->artisan('jwt:generate-certs', [
            '--force' => true,
            '--algo' => 'ec',
            '--sha' => 256,
            '--dir' => $directory,
            '--curve' => 'prime256v1',
            '--ask-passphrase' => true,
        ])
            ->expectsQuestion('Passphrase', '0')
            ->assertSuccessful();

        $this->assertStringContainsString(
            'JWT_PASSPHRASE=0',
            file_get_contents($this->app->environmentFilePath()),
        );
        $this->assertStringContainsString(
            '-----BEGIN ENCRYPTED PRIVATE KEY-----',
            file_get_contents($directory . '/jwt-ec-prime256v1-private.pem'),
        );
    }

    public function testRefusesToOverwriteExistingCertificatesWithoutForce(): void
    {
        $directory = $this->temporaryDirectory('existing');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory . '/jwt-ec-prime256v1-private.pem', 'existing');

        $this->artisan('jwt:generate-certs', [
            '--algo' => 'ec',
            '--sha' => 256,
            '--dir' => $directory,
            '--curve' => 'prime256v1',
        ])
            ->expectsOutputToContain('JWT certificates already exist. Use --force to overwrite them.')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame('existing', file_get_contents($directory . '/jwt-ec-prime256v1-private.pem'));
    }

    public function testFailsWhenEnvironmentFileIsMissing(): void
    {
        $environmentFile = $this->app->environmentFilePath();
        $directory = $this->temporaryDirectory('missing-env');

        unlink($environmentFile);

        $this->artisan('jwt:generate-certs', [
            '--force' => true,
            '--algo' => 'ec',
            '--sha' => 256,
            '--dir' => $directory,
            '--curve' => 'prime256v1',
        ])
            ->expectsOutputToContain("The file [{$environmentFile}] does not exist.")
            ->assertExitCode(Command::FAILURE);

        $this->assertDirectoryDoesNotExist($directory);
    }

    public function testInvalidAlgorithmFailsFast(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown JWT certificate algorithm.');

        $this->artisan('jwt:generate-certs', [
            '--algo' => 'invalid',
            '--dir' => $this->temporaryDirectory('invalid'),
        ]);
    }

    /**
     * Create an isolated temporary directory path for certificate output.
     */
    private function temporaryDirectory(string $suffix): string
    {
        $path = ParallelTesting::tempDir("JwtGenerateCertsCommandTest-{$suffix}");

        $this->filesystem->deleteDirectory($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
