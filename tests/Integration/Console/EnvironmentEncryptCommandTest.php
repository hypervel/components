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

class EnvironmentEncryptCommandTest extends TestCase
{
    protected ArrayFilesystem $filesystem;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new ArrayFilesystem;
        $this->filesystem->files[base_path('.env')] = 'APP_NAME=Hypervel';

        File::swap($this->filesystem);
    }

    public function testItFailsWithInvalidCipherFails(): void
    {
        $this->artisan('env:encrypt', ['--cipher' => 'invalid'])
            ->expectsQuestion('What encryption key would you like to use?', 'generate')
            ->expectsOutputToContain('Unsupported cipher')
            ->assertExitCode(1);

        $this->assertSame([], $this->filesystem->writes);
    }

    public function testItFailsUsingCipherWithInvalidKey(): void
    {
        $this->artisan('env:encrypt', ['--cipher' => 'aes-128-cbc', '--key' => 'invalid'])
            ->expectsOutputToContain('incorrect key length')
            ->assertExitCode(1);

        $this->assertSame([], $this->filesystem->writes);
    }

    public function testItPreservesCancellationWhileReadingTheEnvironment(): void
    {
        $cancellation = new CanceledException('canceled');
        $filesystem = m::mock(Filesystem::class);
        $filesystem->expects('exists')
            ->with(base_path('.env'))
            ->andReturn(true);
        $filesystem->expects('exists')
            ->with(base_path('.env.encrypted'))
            ->andReturn(false);
        $filesystem->expects('get')
            ->with(base_path('.env'))
            ->andThrow($cancellation);
        $filesystem->shouldReceive('replace')->never();
        File::swap($filesystem);

        try {
            $this->artisan('env:encrypt', ['--key' => 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP'])->execute();
            $this->fail('Expected environment encryption to preserve cancellation.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testItGeneratesTheCorrectFileWhenUsingEnvironment(): void
    {
        $this->filesystem->files[base_path('.env.production')] = 'APP_NAME=Hypervel';

        $this->artisan('env:encrypt', ['--env' => 'production'])
            ->expectsQuestion('What encryption key would you like to use?', 'generate')
            ->expectsOutputToContain('.env.production.encrypted')
            ->assertExitCode(0);

        $this->assertSame([base_path('.env.production.encrypted')], $this->filesystem->writes);
        $this->assertNull($this->filesystem->modes[base_path('.env.production.encrypted')]);
    }

    public function testItGeneratesTheCorrectFileWhenNotUsingEnvironment(): void
    {
        $this->artisan('env:encrypt')
            ->expectsQuestion('What encryption key would you like to use?', 'generate')
            ->expectsOutputToContain('.env.encrypted')
            ->assertExitCode(0);

        $this->assertSame([base_path('.env.encrypted')], $this->filesystem->writes);
        $this->assertNull($this->filesystem->modes[base_path('.env.encrypted')]);
        $this->assertSame([], $this->filesystem->deletes);
    }

    public function testItEncryptsNamedEnvironmentFilesBesideAnEnvironmentFileInASubdirectory(): void
    {
        $this->app->loadEnvironmentFrom('secrets/.env');
        $this->filesystem->files[base_path('secrets/.env.production')] = 'APP_NAME=Hypervel';

        $this->artisan('env:encrypt', ['--env' => 'production'])
            ->expectsQuestion('What encryption key would you like to use?', 'generate')
            ->assertExitCode(0);

        $this->assertSame([base_path('secrets/.env.production.encrypted')], $this->filesystem->writes);
        $this->assertNull($this->filesystem->modes[base_path('secrets/.env.production.encrypted')]);
    }

    public function testItFailsWhenEnvironmentFileCannotBeFound(): void
    {
        unset($this->filesystem->files[base_path('.env')]);

        $this->artisan('env:encrypt')
            ->expectsOutputToContain('Environment file not found.')
            ->assertExitCode(1);

        $this->assertSame([], $this->filesystem->writes);
    }

    public function testItFailsWhenEncryptionFileExists(): void
    {
        $this->filesystem->files[base_path('.env.encrypted')] = 'existing';

        $this->artisan('env:encrypt')
            ->expectsQuestion('What encryption key would you like to use?', 'generate')
            ->expectsOutputToContain('Encrypted environment file already exists.')
            ->assertExitCode(1);

        $this->assertSame([], $this->filesystem->writes);
        $this->assertSame('existing', $this->filesystem->files[base_path('.env.encrypted')]);
    }

    public function testItGeneratesTheEncryptionFileWhenForcing(): void
    {
        $this->filesystem->files[base_path('.env.encrypted')] = 'existing';

        $this->artisan('env:encrypt', ['--force' => true])
            ->expectsQuestion('What encryption key would you like to use?', 'generate')
            ->expectsOutputToContain('.env.encrypted')
            ->assertExitCode(0);

        $this->assertSame([base_path('.env.encrypted')], $this->filesystem->writes);
        $this->assertNotSame('existing', $this->filesystem->files[base_path('.env.encrypted')]);
        $this->assertSame(0644, $this->filesystem->modes[base_path('.env.encrypted')]);
    }

    public function testItReencryptsReadableValuesWithANewKeyWhenForcing(): void
    {
        $key = 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP';
        $oldEncrypter = new Encrypter(str_repeat('x', 32), 'AES-256-CBC');

        $this->filesystem->files[base_path('.env')] = 'DB_PASSWORD=1';
        $this->filesystem->files[base_path('.env.encrypted')] = 'DB_PASSWORD=' . $oldEncrypter->encryptString('1') . "\n";

        $this->artisan('env:encrypt', ['--readable' => true, '--force' => true, '--key' => $key])
            ->assertExitCode(0);

        $encryptedOutput = $this->filesystem->files[base_path('.env.encrypted')];

        $encrypter = new Encrypter($key, 'AES-256-CBC');
        $this->assertSame('1', $encrypter->decryptString(substr(rtrim($encryptedOutput), strlen('DB_PASSWORD='))));
        $this->assertSame(0644, $this->filesystem->modes[base_path('.env.encrypted')]);
    }

    public function testItEncryptsWithGivenKeyAndDisplaysIt(): void
    {
        $this->artisan('env:encrypt', ['--key' => $key = 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP'])
            ->expectsOutputToContain('Environment successfully encrypted')
            ->expectsOutputToContain($key)
            ->expectsOutputToContain('.env.encrypted')
            ->assertExitCode(0);

        $encrypter = new Encrypter($key, 'AES-256-CBC');
        $this->assertSame('APP_NAME=Hypervel', $encrypter->decrypt($this->filesystem->files[base_path('.env.encrypted')]));
    }

    public function testItEncryptsWithGivenGeneratedBase64KeyAndDisplaysIt(): void
    {
        $key = Encrypter::generateKey('AES-256-CBC');

        $this->artisan('env:encrypt', ['--key' => 'base64:' . base64_encode($key)])
            ->expectsOutputToContain('Environment successfully encrypted')
            ->expectsOutputToContain('base64:' . base64_encode($key))
            ->expectsOutputToContain('.env.encrypted')
            ->assertExitCode(0);

        $encrypter = new Encrypter($key, 'AES-256-CBC');
        $this->assertSame('APP_NAME=Hypervel', $encrypter->decrypt($this->filesystem->files[base_path('.env.encrypted')]));
    }

    public function testItEncryptsInReadableFormat(): void
    {
        $this->filesystem->files[base_path('.env')] = "APP_NAME=Hypervel\nAPP_ENV=local";

        $this->artisan('env:encrypt', ['--readable' => true, '--key' => 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP'])
            ->expectsOutputToContain('Environment successfully encrypted')
            ->assertExitCode(0);

        $lines = explode("\n", rtrim($this->filesystem->files[base_path('.env.encrypted')]));

        $this->assertCount(2, $lines);
        $this->assertTrue(str_starts_with($lines[0], 'APP_NAME='));
        $this->assertTrue(str_starts_with($lines[1], 'APP_ENV='));
    }

    public function testItSkipsCommentsAndBlankLinesInReadableFormat(): void
    {
        $this->filesystem->files[base_path('.env')] = "# Comment\nAPP_NAME=Hypervel\n\nAPP_ENV=local";

        $this->artisan('env:encrypt', ['--readable' => true, '--key' => 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP'])
            ->expectsOutputToContain('Environment successfully encrypted')
            ->assertExitCode(0);

        $lines = explode("\n", rtrim($this->filesystem->files[base_path('.env.encrypted')]));

        // Comments and blank lines are skipped
        $this->assertCount(2, $lines);
        $this->assertTrue(str_starts_with($lines[0], 'APP_NAME='));
        $this->assertTrue(str_starts_with($lines[1], 'APP_ENV='));
    }

    public function testItEncryptsMultiLineValuesInReadableFormat(): void
    {
        $key = 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        $originalContent = <<<'ENV'
APP_TEST_1="line1
line2
line3"
APP_TEST_2="línea1
lìnea2
lïne3"
ENV;

        $this->filesystem->files[base_path('.env')] = $originalContent;

        $this->artisan('env:encrypt', ['--readable' => true, '--key' => $key])
            ->expectsOutputToContain('Environment successfully encrypted')
            ->assertExitCode(0);

        $encryptedOutput = $this->filesystem->files[base_path('.env.encrypted')];

        // Verify structure
        $lines = explode("\n", rtrim($encryptedOutput));
        $this->assertCount(2, $lines);
        $this->assertTrue(str_starts_with($lines[0], 'APP_TEST_1='));
        $this->assertTrue(str_starts_with($lines[1], 'APP_TEST_2='));

        // Round-trip: decrypt and verify original values
        $encryptedValue1 = substr($lines[0], strlen('APP_TEST_1='));
        $encryptedValue2 = substr($lines[1], strlen('APP_TEST_2='));

        // Quotes are preserved, accented characters are preserved
        $this->assertSame("\"line1\nline2\nline3\"", $encrypter->decryptString($encryptedValue1));
        $this->assertSame("\"línea1\nlìnea2\nlïne3\"", $encrypter->decryptString($encryptedValue2));
    }

    public function testItEncryptsVariableReferencesInReadableFormat(): void
    {
        $key = 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        $originalContent = <<<'ENV'
APP_TEST_1=${APP_TEST}
APP_TEST_2="${APP_TEST}"
ENV;

        $this->filesystem->files[base_path('.env')] = $originalContent;

        $this->artisan('env:encrypt', ['--readable' => true, '--key' => $key])
            ->expectsOutputToContain('Environment successfully encrypted')
            ->assertExitCode(0);

        $encryptedOutput = $this->filesystem->files[base_path('.env.encrypted')];

        // Verify structure
        $lines = explode("\n", rtrim($encryptedOutput));
        $this->assertCount(2, $lines);
        $this->assertTrue(str_starts_with($lines[0], 'APP_TEST_1='));
        $this->assertTrue(str_starts_with($lines[1], 'APP_TEST_2='));

        // Round-trip: decrypt and verify values preserve variable reference syntax
        $encryptedValue1 = substr($lines[0], strlen('APP_TEST_1='));
        $encryptedValue2 = substr($lines[1], strlen('APP_TEST_2='));

        // Unquoted value preserved as-is, quoted value includes quotes
        $this->assertSame('${APP_TEST}', $encrypter->decryptString($encryptedValue1));
        $this->assertSame('"${APP_TEST}"', $encrypter->decryptString($encryptedValue2));
    }

    public function testItSkipsInvalidEnvLinesInReadableFormat(): void
    {
        $key = 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        $originalContent = <<<'ENV'
APP_TEST_1=valid
APP_TEST_2
=nameless
APP_TEST_3=also_valid
ENV;

        $this->filesystem->files[base_path('.env')] = $originalContent;

        $this->artisan('env:encrypt', ['--readable' => true, '--key' => $key])
            ->expectsOutputToContain('Environment successfully encrypted')
            ->assertExitCode(0);

        $encryptedOutput = $this->filesystem->files[base_path('.env.encrypted')];

        // Skip entries without an equals sign or a variable name.
        $lines = explode("\n", rtrim($encryptedOutput));
        $this->assertCount(2, $lines);
        $this->assertTrue(str_starts_with($lines[0], 'APP_TEST_1='));
        $this->assertTrue(str_starts_with($lines[1], 'APP_TEST_3='));

        // Round-trip: decrypt and verify values
        $encryptedValue1 = substr($lines[0], strlen('APP_TEST_1='));
        $encryptedValue3 = substr($lines[1], strlen('APP_TEST_3='));

        $this->assertSame('valid', $encrypter->decryptString($encryptedValue1));
        $this->assertSame('also_valid', $encrypter->decryptString($encryptedValue3));
    }

    public function testItEncryptsSpecialCharactersInReadableFormat(): void
    {
        $key = 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP';
        $encrypter = new Encrypter($key, 'AES-256-CBC');

        $originalContent = <<<'ENV'
NAME_1="Máximus"
NAME_2=M'aximus
NAME_3="M'aximus Decimus Meridius"
ENV;

        $this->filesystem->files[base_path('.env')] = $originalContent;

        $this->artisan('env:encrypt', ['--readable' => true, '--key' => $key])
            ->expectsOutputToContain('Environment successfully encrypted')
            ->assertExitCode(0);

        $encryptedOutput = $this->filesystem->files[base_path('.env.encrypted')];

        // Verify structure
        $lines = explode("\n", rtrim($encryptedOutput));
        $this->assertCount(3, $lines);
        $this->assertTrue(str_starts_with($lines[0], 'NAME_1='));
        $this->assertTrue(str_starts_with($lines[1], 'NAME_2='));
        $this->assertTrue(str_starts_with($lines[2], 'NAME_3='));

        // Round-trip: decrypt and verify special characters are preserved
        $encryptedValue1 = substr($lines[0], strlen('NAME_1='));
        $encryptedValue2 = substr($lines[1], strlen('NAME_2='));
        $encryptedValue3 = substr($lines[2], strlen('NAME_3='));

        // Quoted values include the quotes, unquoted values are as-is
        $this->assertSame('"Máximus"', $encrypter->decryptString($encryptedValue1));
        $this->assertSame("M'aximus", $encrypter->decryptString($encryptedValue2));
        $this->assertSame("\"M'aximus Decimus Meridius\"", $encrypter->decryptString($encryptedValue3));
    }

    public function testItCanRemoveTheOriginalFile(): void
    {
        $this->artisan('env:encrypt', ['--prune' => true])
            ->expectsQuestion('What encryption key would you like to use?', 'generate')
            ->expectsOutputToContain('.env.encrypted')
            ->assertExitCode(0);

        $this->assertSame([base_path('.env.encrypted')], $this->filesystem->writes);
        $this->assertSame([base_path('.env')], $this->filesystem->deletes);
        $this->assertArrayNotHasKey(base_path('.env'), $this->filesystem->files);
    }

    public function testItFailsWhenPrunedEnvironmentFileRemains(): void
    {
        $environmentFile = base_path('.env');
        $encryptedFile = base_path('.env.encrypted');
        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->with($environmentFile)->twice()->andReturn(true, true);
        $filesystem->expects('exists')->with($encryptedFile)->andReturnFalse();
        $filesystem->expects('get')->with($environmentFile)->andReturn('APP_NAME=Hypervel');
        $filesystem->expects('replace')->with($encryptedFile, m::type('string'), null);
        $filesystem->expects('delete')->with($environmentFile)->andReturnFalse();
        File::swap($filesystem);

        $this->artisan('env:encrypt', [
            '--prune' => true,
            '--key' => 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP',
        ])
            ->doesntExpectOutputToContain('Environment successfully encrypted.')
            ->assertExitCode(1);
    }

    public function testItAcceptsConcurrentDisappearanceWhenPruning(): void
    {
        $environmentFile = base_path('.env');
        $encryptedFile = base_path('.env.encrypted');
        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->with($environmentFile)->twice()->andReturn(true, false);
        $filesystem->expects('exists')->with($encryptedFile)->andReturnFalse();
        $filesystem->expects('get')->with($environmentFile)->andReturn('APP_NAME=Hypervel');
        $filesystem->expects('replace')->with($encryptedFile, m::type('string'), null);
        $filesystem->expects('delete')->with($environmentFile)->andReturnFalse();
        File::swap($filesystem);

        $this->artisan('env:encrypt', [
            '--prune' => true,
            '--key' => 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP',
        ])
            ->expectsOutputToContain('Environment successfully encrypted.')
            ->assertSuccessful();
    }

    public function testExistingEncryptedFileSurvivesReplacementFailure(): void
    {
        $tempDir = ParallelTesting::tempDir('EnvironmentEncryptCommandTest');
        $files = new Filesystem;
        $files->deleteDirectory($tempDir);
        $files->ensureDirectoryExists($tempDir);
        $this->app->useEnvironmentPath($tempDir);
        $environmentFile = $tempDir . '/.env';
        $encryptedFile = $environmentFile . '.encrypted';
        $previousContents = 'previous encrypted contents';
        $files->put($environmentFile, 'APP_NAME=Hypervel');
        $files->put($encryptedFile, $previousContents);
        chmod($encryptedFile, 0640);

        $filesystem = m::mock(Filesystem::class)->makePartial();
        $filesystem->expects('replace')
            ->with($encryptedFile, m::type('string'), 0640)
            ->andThrow(new RuntimeException('publication failed'));
        File::swap($filesystem);

        try {
            $this->artisan('env:encrypt', [
                '--force' => true,
                '--key' => 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP',
            ])
                ->expectsOutputToContain('publication failed')
                ->doesntExpectOutputToContain('Environment successfully encrypted.')
                ->assertExitCode(1);

            $this->assertSame($previousContents, $files->get($encryptedFile));
            $this->assertSame(0640, fileperms($encryptedFile) & 0777);
        } finally {
            $files->deleteDirectory($tempDir);
        }
    }

    public function testNewEncryptedFileUsesTheNormalUmask(): void
    {
        $tempDir = ParallelTesting::tempDir('EnvironmentEncryptCommandTest-new');
        $files = new Filesystem;
        $files->deleteDirectory($tempDir);
        $files->ensureDirectoryExists($tempDir);
        $this->app->useEnvironmentPath($tempDir);
        $environmentFile = $tempDir . '/.env';
        $encryptedFile = $environmentFile . '.encrypted';
        $files->put($environmentFile, 'APP_NAME=Hypervel');
        File::swap($files);
        $umask = umask(0027);

        try {
            $this->artisan('env:encrypt', [
                '--key' => 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP',
            ])->assertSuccessful();

            $this->assertFileExists($encryptedFile);
            $this->assertSame(0640, fileperms($encryptedFile) & 0777);
        } finally {
            umask($umask);
            $files->deleteDirectory($tempDir);
        }
    }

    public function testOverwrittenEncryptedFilePreservesExistingPermissions(): void
    {
        $tempDir = ParallelTesting::tempDir('EnvironmentEncryptCommandTest-overwrite');
        $files = new Filesystem;
        $files->deleteDirectory($tempDir);
        $files->ensureDirectoryExists($tempDir);
        $this->app->useEnvironmentPath($tempDir);
        $environmentFile = $tempDir . '/.env';
        $encryptedFile = $environmentFile . '.encrypted';
        $files->put($environmentFile, 'APP_NAME=Hypervel');
        $files->put($encryptedFile, 'previous');
        chmod($encryptedFile, 0644);
        File::swap($files);

        try {
            $this->artisan('env:encrypt', [
                '--force' => true,
                '--key' => 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP',
            ])->assertSuccessful();

            $this->assertSame(0644, fileperms($encryptedFile) & 0777);
        } finally {
            $files->deleteDirectory($tempDir);
        }
    }

    public function testItEncryptsWithInteractivelyGivenKeyAndDisplaysIt(): void
    {
        $this->artisan('env:encrypt')
            ->expectsQuestion('What encryption key would you like to use?', 'ask')
            ->expectsQuestion('What is the encryption key?', $key = 'ANvVbPbE0tWMHpUySh6liY4WaCmAYKXP')
            ->expectsOutputToContain('Environment successfully encrypted')
            ->expectsOutputToContain($key)
            ->expectsOutputToContain('.env.encrypted')
            ->assertExitCode(0);

        $encrypter = new Encrypter($key, 'AES-256-CBC');
        $this->assertSame('APP_NAME=Hypervel', $encrypter->decrypt($this->filesystem->files[base_path('.env.encrypted')]));
    }
}
