<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Hypervel\Encryption\Encrypter;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\File;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Mockery as m;
use RuntimeException;

class EnvironmentDecryptCommandTest extends TestCase
{
    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = m::spy(Filesystem::class);
        $this->filesystem->shouldReceive('replace');
        $this->filesystem->shouldReceive('chmod')->andReturn('0640');
        File::swap($this->filesystem);
    }

    public function testItFailsWithInvalidCipherFails(): void
    {
        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $this->artisan('env:decrypt', ['--cipher' => 'invalid', '--key' => 'abcdefghijklmnop'])
            ->expectsOutputToContain('Unsupported cipher')
            ->assertExitCode(1);
    }

    public function testItFailsUsingCipherWithInvalidKey(): void
    {
        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $this->artisan('env:decrypt', ['--cipher' => 'aes-128-cbc', '--key' => 'invalid'])
            ->expectsOutputToContain('incorrect key length')
            ->assertExitCode(1);
    }

    public function testItFailsWhenEncryptionFileCannotBeFound(): void
    {
        $this->filesystem->shouldReceive('exists')->andReturn(true);

        $this->artisan('env:decrypt', ['--key' => 'secret-key'])
            ->expectsOutputToContain('Environment file already exists.')
            ->assertExitCode(1);
    }

    public function testItFailsWhenEnvironmentFileExists(): void
    {
        $this->filesystem->shouldReceive('exists')->andReturn(false);

        $this->artisan('env:decrypt', ['--key' => 'secret-key'])
            ->expectsOutputToContain('Encrypted environment file not found.')
            ->assertExitCode(1);
    }

    public function testItGeneratesTheEnvironmentFileWithGeneratedKey(): void
    {
        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('get')
            ->once()
            ->andReturn(
                (new Encrypter($key = Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'))
                    ->encrypt('APP_NAME=Laravel')
            );

        $this->artisan('env:decrypt', ['--force' => true, '--key' => 'base64:' . base64_encode($key)])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with(base_path('.env'), 'APP_NAME=Laravel', 0640);
    }

    public function testItGeneratesTheEnvironmentFileWithUserProvidedKey(): void
    {
        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false)
            ->shouldReceive('get')
            ->once()
            ->andReturn(
                (new Encrypter('abcdefghijklmnop', 'aes-128-gcm'))
                    ->encrypt('APP_NAME="Laravel Two"')
            );

        $this->artisan('env:decrypt', ['--cipher' => 'aes-128-gcm', '--key' => 'abcdefghijklmnop'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with(base_path('.env'), 'APP_NAME="Laravel Two"', 0600);
    }

    public function testItGeneratesTheEnvironmentFileWithKeyFromEnvironment(): void
    {
        $hadEncryptionKey = array_key_exists('HYPERVEL_ENV_ENCRYPTION_KEY', $_SERVER);
        $previousEncryptionKey = $_SERVER['HYPERVEL_ENV_ENCRYPTION_KEY'] ?? null;
        $_SERVER['HYPERVEL_ENV_ENCRYPTION_KEY'] = 'ponmlkjihgfedcbaponmlkjihgfedcba';

        try {
            $this->filesystem->shouldReceive('exists')
                ->once()
                ->andReturn(true)
                ->shouldReceive('exists')
                ->once()
                ->andReturn(false)
                ->shouldReceive('get')
                ->once()
                ->andReturn(
                    (new Encrypter('ponmlkjihgfedcbaponmlkjihgfedcba', 'AES-256-CBC'))
                        ->encrypt('APP_NAME="Laravel Three"')
                );

            $this->artisan('env:decrypt')
                ->expectsOutputToContain('Environment successfully decrypted.')
                ->assertExitCode(0);

            $this->filesystem->shouldHaveReceived('replace')
                ->with(base_path('.env'), 'APP_NAME="Laravel Three"', 0600);
        } finally {
            if ($hadEncryptionKey) {
                $_SERVER['HYPERVEL_ENV_ENCRYPTION_KEY'] = $previousEncryptionKey;
            } else {
                unset($_SERVER['HYPERVEL_ENV_ENCRYPTION_KEY']);
            }
        }
    }

    public function testItGeneratesTheEnvironmentFileWhenForcing(): void
    {
        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('get')
            ->once()
            ->andReturn(
                (new Encrypter('abcdefghijklmnop', 'aes-128-gcm'))
                    ->encrypt('APP_NAME="Laravel Two"')
            );

        $this->artisan('env:decrypt', ['--force' => true, '--key' => 'abcdefghijklmnop', '--cipher' => 'aes-128-gcm'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with(base_path('.env'), 'APP_NAME="Laravel Two"', 0640);
    }

    public function testItDecryptsMultiLineEnvironmentCorrectly(): void
    {
        $contents = <<<'Text'
        APP_NAME=Laravel
        APP_ENV=local
        APP_DEBUG=true
        APP_URL=http://localhost

        LOG_CHANNEL=stack
        LOG_DEPRECATIONS_CHANNEL=null
        LOG_LEVEL=debug

        DB_CONNECTION=mysql
        DB_HOST=127.0.0.1
        DB_PORT=3306
        DB_DATABASE=laravel
        DB_USERNAME=root
        DB_PASSWORD=
        Text;

        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('get')
            ->once()
            ->andReturn(
                (new Encrypter('abcdefghijklmnop', 'aes-128-gcm'))
                    ->encrypt($contents)
            );

        $this->artisan('env:decrypt', ['--force' => true, '--key' => 'abcdefghijklmnop', '--cipher' => 'aes-128-gcm'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with(base_path('.env'), $contents, 0640);
    }

    public function testItWritesTheEnvironmentFileCustomFilename(): void
    {
        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false)
            ->shouldReceive('get')
            ->once()
            ->andReturn(
                (new Encrypter('abcdefghijklmnopabcdefghijklmnop', 'AES-256-CBC'))
                    ->encrypt('APP_NAME="Laravel Two"')
            );

        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnopabcdefghijklmnop', '--filename' => '.env'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with(base_path('.env'), 'APP_NAME="Laravel Two"', 0600);
    }

    public function testItWritesTheEnvironmentFileCustomPath(): void
    {
        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false)
            ->shouldReceive('get')
            ->once()
            ->andReturn(
                (new Encrypter('abcdefghijklmnopabcdefghijklmnop', 'AES-256-CBC'))
                    ->encrypt('APP_NAME="Laravel Two"')
            );

        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnopabcdefghijklmnop', '--path' => '/tmp'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with('/tmp' . DIRECTORY_SEPARATOR . '.env.production', 'APP_NAME="Laravel Two"', 0600);
    }

    public function testItWritesTheEnvironmentFileCustomPathAndFilename(): void
    {
        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false)
            ->shouldReceive('get')
            ->once()
            ->andReturn(
                (new Encrypter('abcdefghijklmnopabcdefghijklmnop', 'AES-256-CBC'))
                    ->encrypt('APP_NAME="Laravel Two"')
            );

        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnopabcdefghijklmnop', '--filename' => '.env', '--path' => '/tmp'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with('/tmp' . DIRECTORY_SEPARATOR . '.env', 'APP_NAME="Laravel Two"', 0600);
    }

    public function testItCannotOverwriteEncryptedFiles(): void
    {
        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnop', '--filename' => '.env.production.encrypted'])
            ->expectsOutputToContain('Invalid filename.')
            ->assertExitCode(1);

        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnop', '--filename' => '.env.staging.encrypted'])
            ->expectsOutputToContain('Invalid filename.')
            ->assertExitCode(1);
    }

    public function testItGeneratesTheEnvironmentFileWithInteractivelyUserProvidedKey(): void
    {
        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false)
            ->shouldReceive('get')
            ->once()
            ->andReturn(
                (new Encrypter($key = 'abcdefghijklmnop', 'aes-128-gcm'))
                    ->encrypt('APP_NAME="Laravel Two"')
            );

        $this->artisan('env:decrypt', ['--cipher' => 'aes-128-gcm'])
            ->expectsQuestion('What is the decryption key?', $key)
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with(base_path('.env'), 'APP_NAME="Laravel Two"', 0600);
    }

    public function testItAutoDetectsAndDecryptsReadableFormat(): void
    {
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        // Create readable format encrypted content
        $encryptedContent = 'APP_NAME=' . $encrypter->encryptString('Laravel') . "\n"
                           . 'APP_ENV=' . $encrypter->encryptString('local');

        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false)
            ->shouldReceive('get')
            ->once()
            ->andReturn($encryptedContent);

        $this->artisan('env:decrypt', ['--key' => $key])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with(base_path('.env'), "APP_NAME=Laravel\nAPP_ENV=local\n", 0600);
    }

    public function testItStillDecryptsBlobFormat(): void
    {
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        // Create blob format (entire file encrypted as one)
        $originalContent = "APP_NAME=Laravel\nAPP_ENV=local";
        $encryptedContent = $encrypter->encrypt($originalContent);

        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false)
            ->shouldReceive('get')
            ->once()
            ->andReturn($encryptedContent);

        $this->artisan('env:decrypt', ['--key' => $key])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with(base_path('.env'), $originalContent, 0600);
    }

    public function testItDecryptsBlobFormatWithNewlineInContent(): void
    {
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        // Create blob format and inject a newline (simulating wrapped base64)
        $originalContent = "APP_NAME=Laravel\nAPP_ENV=local";
        $encryptedContent = $encrypter->encrypt($originalContent);

        // Insert a newline in the middle of the base64 string
        $midpoint = (int) (strlen($encryptedContent) / 2);
        $encryptedContentWithNewline = substr($encryptedContent, 0, $midpoint) . "\n" . substr($encryptedContent, $midpoint);

        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false)
            ->shouldReceive('get')
            ->once()
            ->andReturn($encryptedContentWithNewline);

        $this->artisan('env:decrypt', ['--key' => $key])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with(base_path('.env'), $originalContent, 0600);
    }

    public function testItDecryptsReadableFormatWithBase64Values(): void
    {
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        // Create readable format with base64 value containing = signs
        $encryptedContent = 'APP_KEY=' . $encrypter->encryptString('base64:Ge+W23u+VZI2tbrp5QCGWrsUuxgcD65i7jtTRR2ZqfY=') . "\n"
                           . 'APP_ENV=' . $encrypter->encryptString('local');

        $this->filesystem->shouldReceive('exists')
            ->once()
            ->andReturn(true)
            ->shouldReceive('exists')
            ->once()
            ->andReturn(false)
            ->shouldReceive('get')
            ->once()
            ->andReturn($encryptedContent);

        $this->artisan('env:decrypt', ['--key' => $key])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->filesystem->shouldHaveReceived('replace')
            ->with(base_path('.env'), "APP_KEY=base64:Ge+W23u+VZI2tbrp5QCGWrsUuxgcD65i7jtTRR2ZqfY=\nAPP_ENV=local\n", 0600);
    }

    public function testNewEnvironmentFileUsesPrivatePermissions(): void
    {
        $tempDir = ParallelTesting::tempDir('EnvironmentDecryptCommandTest-new');
        $files = new Filesystem;
        $files->ensureDirectoryExists($tempDir);
        $this->app->useEnvironmentPath($tempDir);
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encryptedFile = $tempDir . '/.env.encrypted';
        $outputFile = $tempDir . '/.env';
        $files->put(
            $encryptedFile,
            (new Encrypter($key, 'AES-256-CBC'))->encrypt('APP_NAME=Hypervel'),
        );
        File::swap($files);

        try {
            $this->artisan('env:decrypt', ['--key' => $key])->assertSuccessful();

            $this->assertSame('APP_NAME=Hypervel', $files->get($outputFile));
            $this->assertSame(0600, fileperms($outputFile) & 0777);
        } finally {
            $files->deleteDirectory($tempDir);
        }
    }

    public function testOverwrittenEnvironmentFilePreservesExistingPermissions(): void
    {
        $tempDir = ParallelTesting::tempDir('EnvironmentDecryptCommandTest-overwrite');
        $files = new Filesystem;
        $files->ensureDirectoryExists($tempDir);
        $this->app->useEnvironmentPath($tempDir);
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encryptedFile = $tempDir . '/.env.encrypted';
        $outputFile = $tempDir . '/.env';
        $files->put(
            $encryptedFile,
            (new Encrypter($key, 'AES-256-CBC'))->encrypt('APP_NAME=Hypervel'),
        );
        $files->put($outputFile, 'previous');
        chmod($outputFile, 0644);
        File::swap($files);

        try {
            $this->artisan('env:decrypt', ['--force' => true, '--key' => $key])->assertSuccessful();

            $this->assertSame('APP_NAME=Hypervel', $files->get($outputFile));
            $this->assertSame(0644, fileperms($outputFile) & 0777);
        } finally {
            $files->deleteDirectory($tempDir);
        }
    }

    public function testExistingEnvironmentFileSurvivesReplacementFailure(): void
    {
        $tempDir = ParallelTesting::tempDir('EnvironmentDecryptCommandTest');
        $files = new Filesystem;
        $files->ensureDirectoryExists($tempDir);
        $this->app->useEnvironmentPath($tempDir);
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encryptedFile = $tempDir . '/.env.encrypted';
        $outputFile = $tempDir . '/.env';
        $previousContents = 'previous plaintext contents';
        $files->put(
            $encryptedFile,
            (new Encrypter($key, 'AES-256-CBC'))->encrypt('APP_NAME=Hypervel'),
        );
        $files->put($outputFile, $previousContents);
        chmod($outputFile, 0640);

        $filesystem = m::mock(Filesystem::class)->makePartial();
        $filesystem->shouldReceive('replace')
            ->once()
            ->with($outputFile, 'APP_NAME=Hypervel', 0640)
            ->andThrow(new RuntimeException('publication failed'));
        File::swap($filesystem);

        try {
            $this->artisan('env:decrypt', [
                '--force' => true,
                '--key' => $key,
            ])
                ->expectsOutputToContain('publication failed')
                ->doesntExpectOutputToContain('Environment successfully decrypted.')
                ->assertExitCode(1);

            $this->assertSame($previousContents, $files->get($outputFile));
            $this->assertSame(0640, fileperms($outputFile) & 0777);
        } finally {
            $files->deleteDirectory($tempDir);
        }
    }
}
