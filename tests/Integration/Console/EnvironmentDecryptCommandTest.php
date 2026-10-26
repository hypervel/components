<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Hypervel\Encryption\Encrypter;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\File;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Integration\Console\Fixtures\ArrayFilesystem;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class EnvironmentDecryptCommandTest extends TestCase
{
    protected ArrayFilesystem $filesystem;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new ArrayFilesystem;

        File::swap($this->filesystem);
    }

    public function testItFailsWithInvalidCipherFails(): void
    {
        $this->filesystem->files[base_path('.env.encrypted')] = 'encrypted';

        $this->artisan('env:decrypt', ['--cipher' => 'invalid', '--key' => 'abcdefghijklmnop'])
            ->expectsOutputToContain('Unsupported cipher')
            ->assertExitCode(1);

        $this->assertSame([], $this->filesystem->writes);
    }

    public function testItFailsUsingCipherWithInvalidKey(): void
    {
        $this->filesystem->files[base_path('.env.encrypted')] = 'encrypted';

        $this->artisan('env:decrypt', ['--cipher' => 'aes-128-cbc', '--key' => 'invalid'])
            ->expectsOutputToContain('incorrect key length')
            ->assertExitCode(1);

        $this->assertSame([], $this->filesystem->writes);
    }

    public function testItPreservesCancellationWhileReadingTheEncryptedEnvironment(): void
    {
        $cancellation = new CanceledException('canceled');
        $filesystem = m::mock(Filesystem::class);
        $filesystem->expects('exists')
            ->with(base_path('.env.encrypted'))
            ->andReturn(true);
        $filesystem->expects('exists')
            ->with(base_path('.env'))
            ->andReturn(false);
        $filesystem->expects('get')
            ->with(base_path('.env.encrypted'))
            ->andThrow($cancellation);
        File::swap($filesystem);

        try {
            $this->artisan('env:decrypt', ['--key' => 'abcdefghijklmnopabcdefghijklmnop'])->execute();
            $this->fail('Expected environment decryption to preserve cancellation.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testItFailsWhenEnvironmentFileExists(): void
    {
        $this->filesystem->files[base_path('.env.encrypted')] = 'encrypted';
        $this->filesystem->files[base_path('.env')] = 'existing';

        $this->artisan('env:decrypt', ['--key' => 'secret-key'])
            ->expectsOutputToContain('Environment file already exists.')
            ->assertExitCode(1);

        $this->assertSame([], $this->filesystem->writes);
    }

    public function testItFailsWhenEncryptionFileCannotBeFound(): void
    {
        $this->artisan('env:decrypt', ['--key' => 'secret-key'])
            ->expectsOutputToContain('Encrypted environment file not found.')
            ->assertExitCode(1);

        $this->assertSame([], $this->filesystem->writes);
    }

    public function testItGeneratesTheEnvironmentFileWithGeneratedKey(): void
    {
        $this->filesystem->files[base_path('.env.encrypted')] = (new Encrypter($key = Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'))
            ->encrypt('APP_NAME=Hypervel');
        $this->filesystem->files[base_path('.env')] = 'existing';

        $this->artisan('env:decrypt', ['--force' => true, '--key' => 'base64:' . base64_encode($key)])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame('APP_NAME=Hypervel', $this->filesystem->files[base_path('.env')]);
        $this->assertSame(0644, $this->filesystem->modes[base_path('.env')]);
    }

    public function testItGeneratesTheEnvironmentFileWithUserProvidedKey(): void
    {
        $this->filesystem->files[base_path('.env.encrypted')] = (new Encrypter('abcdefghijklmnop', 'aes-128-gcm'))
            ->encrypt('APP_NAME="Hypervel Two"');

        $this->artisan('env:decrypt', ['--cipher' => 'aes-128-gcm', '--key' => 'abcdefghijklmnop'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame('APP_NAME="Hypervel Two"', $this->filesystem->files[base_path('.env')]);
        $this->assertSame(0600, $this->filesystem->modes[base_path('.env')]);
    }

    public function testItGeneratesTheEnvironmentFileWithKeyFromEnvironment(): void
    {
        $hadEncryptionKey = array_key_exists('HYPERVEL_ENV_ENCRYPTION_KEY', $_SERVER);
        $previousEncryptionKey = $_SERVER['HYPERVEL_ENV_ENCRYPTION_KEY'] ?? null;
        $_SERVER['HYPERVEL_ENV_ENCRYPTION_KEY'] = 'ponmlkjihgfedcbaponmlkjihgfedcba';

        try {
            $this->filesystem->files[base_path('.env.encrypted')] = (new Encrypter('ponmlkjihgfedcbaponmlkjihgfedcba', 'AES-256-CBC'))
                ->encrypt('APP_NAME="Hypervel Three"');

            $this->artisan('env:decrypt')
                ->expectsOutputToContain('Environment successfully decrypted.')
                ->assertExitCode(0);

            $this->assertSame('APP_NAME="Hypervel Three"', $this->filesystem->files[base_path('.env')]);
            $this->assertSame(0600, $this->filesystem->modes[base_path('.env')]);
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
        $this->filesystem->files[base_path('.env.encrypted')] = (new Encrypter('abcdefghijklmnop', 'aes-128-gcm'))
            ->encrypt('APP_NAME="Hypervel Two"');
        $this->filesystem->files[base_path('.env')] = 'existing';

        $this->artisan('env:decrypt', ['--force' => true, '--key' => 'abcdefghijklmnop', '--cipher' => 'aes-128-gcm'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame('APP_NAME="Hypervel Two"', $this->filesystem->files[base_path('.env')]);
        $this->assertSame(0644, $this->filesystem->modes[base_path('.env')]);
    }

    public function testItDecryptsMultiLineEnvironmentCorrectly(): void
    {
        $contents = <<<'Text'
        APP_NAME=Hypervel
        APP_ENV=local
        APP_DEBUG=true
        APP_URL=http://localhost

        LOG_CHANNEL=stack
        LOG_DEPRECATIONS_CHANNEL=null
        LOG_LEVEL=debug

        DB_CONNECTION=mysql
        DB_HOST=127.0.0.1
        DB_PORT=3306
        DB_DATABASE=hypervel
        DB_USERNAME=root
        DB_PASSWORD=
        Text;

        $this->filesystem->files[base_path('.env.encrypted')] = (new Encrypter('abcdefghijklmnop', 'aes-128-gcm'))
            ->encrypt($contents);
        $this->filesystem->files[base_path('.env')] = 'existing';

        $this->artisan('env:decrypt', ['--force' => true, '--key' => 'abcdefghijklmnop', '--cipher' => 'aes-128-gcm'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame($contents, $this->filesystem->files[base_path('.env')]);
        $this->assertSame(0644, $this->filesystem->modes[base_path('.env')]);
    }

    public function testItWritesTheEnvironmentFileCustomFilename(): void
    {
        $this->filesystem->files[base_path('.env.production.encrypted')] = (new Encrypter('abcdefghijklmnopabcdefghijklmnop', 'AES-256-CBC'))
            ->encrypt('APP_NAME="Hypervel Two"');

        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnopabcdefghijklmnop', '--filename' => '.env'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame('APP_NAME="Hypervel Two"', $this->filesystem->files[base_path('.env')]);
        $this->assertSame(0600, $this->filesystem->modes[base_path('.env')]);
    }

    public function testItWritesTheEnvironmentFileCustomPath(): void
    {
        $this->filesystem->files[base_path('.env.production.encrypted')] = (new Encrypter('abcdefghijklmnopabcdefghijklmnop', 'AES-256-CBC'))
            ->encrypt('APP_NAME="Hypervel Two"');

        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnopabcdefghijklmnop', '--path' => '/tmp'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame('APP_NAME="Hypervel Two"', $this->filesystem->files['/tmp' . DIRECTORY_SEPARATOR . '.env.production']);
        $this->assertSame(0600, $this->filesystem->modes['/tmp' . DIRECTORY_SEPARATOR . '.env.production']);
    }

    public function testItWritesTheEnvironmentFileCustomPathAndFilename(): void
    {
        $this->filesystem->files[base_path('.env.production.encrypted')] = (new Encrypter('abcdefghijklmnopabcdefghijklmnop', 'AES-256-CBC'))
            ->encrypt('APP_NAME="Hypervel Two"');

        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnopabcdefghijklmnop', '--filename' => '.env', '--path' => '/tmp'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame('APP_NAME="Hypervel Two"', $this->filesystem->files['/tmp' . DIRECTORY_SEPARATOR . '.env']);
        $this->assertSame(0600, $this->filesystem->modes['/tmp' . DIRECTORY_SEPARATOR . '.env']);
    }

    public function testItDecryptsNamedEnvironmentFilesBesideAnEnvironmentFileInASubdirectory(): void
    {
        $this->app->loadEnvironmentFrom('secrets/.env');
        $this->filesystem->files[base_path('secrets/.env.production.encrypted')] = (new Encrypter('abcdefghijklmnopabcdefghijklmnop', 'AES-256-CBC'))
            ->encrypt('APP_NAME=Hypervel');

        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnopabcdefghijklmnop'])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame('APP_NAME=Hypervel', $this->filesystem->files[base_path('secrets/.env.production')]);
        $this->assertSame(0600, $this->filesystem->modes[base_path('secrets/.env.production')]);
    }

    public function testItCannotOverwriteEncryptedFiles(): void
    {
        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnop', '--filename' => '.env.production.encrypted'])
            ->expectsOutputToContain('Invalid filename.')
            ->assertExitCode(1);

        $this->assertSame([], $this->filesystem->writes);

        $this->artisan('env:decrypt', ['--env' => 'production', '--key' => 'abcdefghijklmnop', '--filename' => '.env.staging.encrypted'])
            ->expectsOutputToContain('Invalid filename.')
            ->assertExitCode(1);

        $this->assertSame([], $this->filesystem->writes);
    }

    public function testItGeneratesTheEnvironmentFileWithInteractivelyUserProvidedKey(): void
    {
        $this->filesystem->files[base_path('.env.encrypted')] = (new Encrypter($key = 'abcdefghijklmnop', 'aes-128-gcm'))
            ->encrypt('APP_NAME="Hypervel Two"');

        $this->artisan('env:decrypt', ['--cipher' => 'aes-128-gcm'])
            ->expectsQuestion('What is the decryption key?', $key)
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame('APP_NAME="Hypervel Two"', $this->filesystem->files[base_path('.env')]);
        $this->assertSame(0600, $this->filesystem->modes[base_path('.env')]);
    }

    public function testItAutoDetectsAndDecryptsReadableFormat(): void
    {
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        // Create readable format encrypted content
        $encryptedContent = 'APP_NAME=' . $encrypter->encryptString('Hypervel') . "\n"
                           . 'APP_ENV=' . $encrypter->encryptString('local');

        $this->filesystem->files[base_path('.env.encrypted')] = $encryptedContent;

        $this->artisan('env:decrypt', ['--key' => $key])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame("APP_NAME=Hypervel\nAPP_ENV=local\n", $this->filesystem->files[base_path('.env')]);
        $this->assertSame(0600, $this->filesystem->modes[base_path('.env')]);
    }

    public function testItStillDecryptsBlobFormat(): void
    {
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        // Create blob format (entire file encrypted as one)
        $originalContent = "APP_NAME=Hypervel\nAPP_ENV=local";
        $encryptedContent = $encrypter->encrypt($originalContent);

        $this->filesystem->files[base_path('.env.encrypted')] = $encryptedContent;

        $this->artisan('env:decrypt', ['--key' => $key])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame($originalContent, $this->filesystem->files[base_path('.env')]);
        $this->assertSame(0600, $this->filesystem->modes[base_path('.env')]);
    }

    public function testItDecryptsBlobFormatWithNewlineInContent(): void
    {
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        // Create blob format and inject a newline (simulating wrapped base64)
        $originalContent = "APP_NAME=Hypervel\nAPP_ENV=local";
        $encryptedContent = $encrypter->encrypt($originalContent);

        // Insert a newline in the middle of the base64 string
        $midpoint = (int) (strlen($encryptedContent) / 2);
        $encryptedContentWithNewline = substr($encryptedContent, 0, $midpoint) . "\n" . substr($encryptedContent, $midpoint);

        $this->filesystem->files[base_path('.env.encrypted')] = $encryptedContentWithNewline;

        $this->artisan('env:decrypt', ['--key' => $key])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame($originalContent, $this->filesystem->files[base_path('.env')]);
        $this->assertSame(0600, $this->filesystem->modes[base_path('.env')]);
    }

    public function testItDecryptsReadableFormatWithBase64Values(): void
    {
        $key = 'abcdefghijklmnopabcdefghijklmnop';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        // Create readable format with base64 value containing = signs
        $encryptedContent = 'APP_KEY=' . $encrypter->encryptString('base64:Ge+W23u+VZI2tbrp5QCGWrsUuxgcD65i7jtTRR2ZqfY=') . "\n"
                           . 'APP_ENV=' . $encrypter->encryptString('local');

        $this->filesystem->files[base_path('.env.encrypted')] = $encryptedContent;

        $this->artisan('env:decrypt', ['--key' => $key])
            ->expectsOutputToContain('Environment successfully decrypted.')
            ->assertExitCode(0);

        $this->assertSame("APP_KEY=base64:Ge+W23u+VZI2tbrp5QCGWrsUuxgcD65i7jtTRR2ZqfY=\nAPP_ENV=local\n", $this->filesystem->files[base_path('.env')]);
        $this->assertSame(0600, $this->filesystem->modes[base_path('.env')]);
    }

    public function testNewEnvironmentFileUsesPrivatePermissions(): void
    {
        $tempDir = ParallelTesting::tempDir('EnvironmentDecryptCommandTest-new');
        $files = new Filesystem;
        $files->deleteDirectory($tempDir);
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
        $files->deleteDirectory($tempDir);
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
        $files->deleteDirectory($tempDir);
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
        $filesystem->expects('replace')
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
