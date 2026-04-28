<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Encryption;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase;

class KeyGenerateCommandTest extends TestCase
{
    private string $envDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envDir = sys_get_temp_dir() . '/hypervel-key-test-' . uniqid();
        mkdir($this->envDir);
    }

    protected function tearDown(): void
    {
        $envFile = $this->envDir . '/.env';

        if (file_exists($envFile)) {
            unlink($envFile);
        }

        if (is_dir($this->envDir)) {
            rmdir($this->envDir);
        }

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
}
