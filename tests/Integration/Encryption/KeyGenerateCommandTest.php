<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Encryption;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Encryption\Commands\KeyGenerateCommand;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class KeyGenerateCommandTest extends TestCase
{
    private string $envDir;

    private Filesystem $filesystem;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->envDir = ParallelTesting::tempDir('KeyGenerateCommandTest');
        $this->filesystem->deleteDirectory($this->envDir);
        $this->filesystem->ensureDirectoryExists($this->envDir);
        $this->app->useEnvironmentPath($this->envDir);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->envDir);

        parent::tearDown();
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('app.cipher', 'aes-128-cbc');
    }

    public function testShowOptionDisplaysKeyWithoutModifyingFiles()
    {
        $this->app['config']->set('app.key', '');

        file_put_contents($this->envDir . '/.env', 'APP_KEY=');
        $this->app->useEnvironmentPath($this->envDir);

        $this->artisan('key:generate', ['--show' => true])
            ->expectsOutputToContain('base64:')
            ->assertSuccessful();

        // .env file should remain unchanged
        $this->assertSame('APP_KEY=', file_get_contents($this->envDir . '/.env'));
    }

    public function testKeyIsWrittenToEnvFile()
    {
        $this->app['config']->set('app.key', '');

        file_put_contents($this->envDir . '/.env', 'APP_KEY=');
        $this->app->useEnvironmentPath($this->envDir);

        $this->artisan('key:generate')
            ->expectsOutputToContain('Application key set successfully.')
            ->assertSuccessful();

        $envContents = file_get_contents($this->envDir . '/.env');
        $this->assertStringStartsWith('APP_KEY=base64:', $envContents);

        // Config should also be updated
        $this->assertStringStartsWith('base64:', $this->app['config']['app.key']);
    }

    public function testKeyIsWrittenToEnvFileWhenCurrentConfigKeyIsNull(): void
    {
        $this->app['config']->set('app.key', null);

        file_put_contents($this->envDir . '/.env', 'APP_KEY=');
        $this->app->useEnvironmentPath($this->envDir);

        $this->artisan('key:generate')
            ->expectsOutputToContain('Application key set successfully.')
            ->assertSuccessful();

        $envContents = file_get_contents($this->envDir . '/.env');
        $this->assertStringStartsWith('APP_KEY=base64:', $envContents);
        $this->assertStringStartsWith('base64:', $this->app['config']['app.key']);
    }

    public function testForceOptionBypassesConfirmationInProduction()
    {
        $this->app['env'] = 'production';
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 16)));

        file_put_contents($this->envDir . '/.env', 'APP_KEY=base64:' . base64_encode(str_repeat('a', 16)));
        $this->app->useEnvironmentPath($this->envDir);

        $this->artisan('key:generate', ['--force' => true])
            ->expectsOutputToContain('Application key set successfully.')
            ->assertSuccessful();

        // Key should have been replaced
        $envContents = file_get_contents($this->envDir . '/.env');
        $this->assertStringStartsWith('APP_KEY=base64:', $envContents);
        $this->assertStringNotContainsString(base64_encode(str_repeat('a', 16)), $envContents);
    }

    public function testErrorWhenEnvFileHasNoAppKeyLine()
    {
        $this->app['config']->set('app.key', '');

        file_put_contents($this->envDir . '/.env', 'APP_NAME=Hypervel');
        $this->app->useEnvironmentPath($this->envDir);

        $this->artisan('key:generate')
            ->expectsOutputToContain('No APP_KEY variable was found in the .env file.')
            ->assertSuccessful();
    }

    public function testGeneratedKeyHasCorrectLengthForCipher()
    {
        $this->app['config']->set('app.key', '');
        $this->app['config']->set('app.cipher', 'aes-256-cbc');

        file_put_contents($this->envDir . '/.env', 'APP_KEY=');
        $this->app->useEnvironmentPath($this->envDir);

        $this->artisan('key:generate')
            ->expectsOutputToContain('Application key set successfully.')
            ->assertSuccessful();

        // AES-256 needs a 32-byte key, which base64-encodes to 44 characters
        $envContents = file_get_contents($this->envDir . '/.env');
        preg_match('/APP_KEY=base64:(.+)/', $envContents, $matches);
        $this->assertNotEmpty($matches[1]);
        $this->assertSame(32, strlen(base64_decode($matches[1])));
    }

    public function testProhibitedCommandDoesNotGenerateOrPublishAKey(): void
    {
        $this->app['config']->set('app.key', '');
        $path = $this->envDir . '/.env';
        file_put_contents($path, 'APP_KEY=');
        KeyGenerateCommand::prohibit();

        $this->artisan('key:generate')
            ->expectsOutputToContain('This command is prohibited from running in this environment.')
            ->assertSuccessful();

        $this->assertSame('APP_KEY=', file_get_contents($path));
        $this->assertSame('', $this->app['config']->get('app.key'));
    }

    #[DataProvider('quotedKeyLines')]
    public function testExactQuotedKeyLinesAreReplaced(string $configuredKey, string $line, string $suffix): void
    {
        $this->app['config']->set('app.key', $configuredKey);
        $path = $this->envDir . '/.env';
        file_put_contents($path, $line);

        $this->artisan('key:generate', ['--force' => true])
            ->expectsOutputToContain('Application key set successfully.')
            ->assertSuccessful();

        $contents = file_get_contents($path);
        $generatedKey = $this->app['config']->get('app.key');

        $this->assertIsString($generatedKey);
        $this->assertStringStartsWith('base64:', $generatedKey);
        $this->assertSame("APP_KEY={$generatedKey}{$suffix}", $contents);
    }

    /**
     * Provide supported quoted environment key lines.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function quotedKeyLines(): array
    {
        return [
            'empty double quotes' => ['', 'APP_KEY=""', ''],
            'empty single quotes' => ['', "APP_KEY=''", ''],
            'quoted current key' => ['base64:current', 'APP_KEY="base64:current"', ''],
            'CRLF quoted current key' => ['base64:current', "APP_KEY=\"base64:current\"\r\nAPP_NAME=Hypervel", "\r\nAPP_NAME=Hypervel"],
        ];
    }

    #[DataProvider('nonMatchingKeyLines')]
    public function testNonMatchingKeyLinesAreNotReplaced(string $line): void
    {
        $this->app['config']->set('app.key', 'base64:current');
        $path = $this->envDir . '/.env';
        file_put_contents($path, $line);

        $this->artisan('key:generate', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame($line, file_get_contents($path));
        $this->assertSame('base64:current', $this->app['config']->get('app.key'));
    }

    /**
     * Provide environment key lines that do not exactly match the configured key.
     *
     * @return array<string, array{string}>
     */
    public static function nonMatchingKeyLines(): array
    {
        return [
            'longer prefixed key' => ['APP_KEY=base64:currentsuffix'],
            'mismatched quotes' => ['APP_KEY="base64:current\''],
        ];
    }

    public function testMissingEnvironmentFileThrowsTheFilesystemException(): void
    {
        $this->app['config']->set('app.key', '');

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('File does not exist at path');

        $this->artisan('key:generate')->run();
    }

    public function testEnvironmentReadFailureRemainsAFileNotFoundException(): void
    {
        $this->app['config']->set('app.key', '');
        $path = $this->envDir . '/.env';
        file_put_contents($path, 'APP_KEY=');
        $filesystem = new FaultingKeyEnvironmentFilesystem;
        $filesystem->getFailure = new FileNotFoundException("Unable to read file at path {$path}.");
        $this->app->instance(Filesystem::class, $filesystem);

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage("Unable to read file at path {$path}.");

        $this->artisan('key:generate')->run();
    }

    public function testEnvironmentReplacementFailureDoesNotPublishPartialState(): void
    {
        $this->app['config']->set('app.key', '');
        $path = $this->envDir . '/.env';
        file_put_contents($path, 'APP_KEY=');
        $filesystem = new FaultingKeyEnvironmentFilesystem;
        $filesystem->replaceFailure = new RuntimeException('Unable to write the complete replacement contents.');
        $this->app->instance(Filesystem::class, $filesystem);

        try {
            $this->artisan('key:generate')->run();

            $this->fail('Expected the replacement failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to write the complete replacement contents.', $exception->getMessage());
        }

        $this->assertSame('APP_KEY=', file_get_contents($path));
        $this->assertSame('', $this->app['config']->get('app.key'));
    }

    public function testEnvironmentFileModeIsPreservedWhenTheKeyIsReplaced(): void
    {
        $this->app['config']->set('app.key', '');
        $path = $this->envDir . '/.env';
        file_put_contents($path, 'APP_KEY=');
        chmod($path, 0640);

        $this->artisan('key:generate')
            ->assertSuccessful();

        clearstatcache(true, $path);
        $this->assertSame(0640, fileperms($path) & 0777);
    }
}

class FaultingKeyEnvironmentFilesystem extends Filesystem
{
    public ?Throwable $getFailure = null;

    public ?Throwable $replaceFailure = null;

    /**
     * Get the contents of a file.
     */
    #[Override]
    public function get(string $path, bool $lock = false): string
    {
        if ($this->getFailure !== null) {
            throw $this->getFailure;
        }

        return parent::get($path, $lock);
    }

    /**
     * Write the contents of a file, replacing it atomically if it already exists.
     */
    #[Override]
    public function replace(string $path, string $content, ?int $mode = null): void
    {
        if ($this->replaceFailure !== null) {
            throw $this->replaceFailure;
        }

        parent::replace($path, $content, $mode);
    }
}
