<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\Integration\Generators\TestCase;
use LogicException;

/**
 * @internal
 * @coversNothing
 */
class ConfigCacheCommandTest extends TestCase
{
    protected $files = [
        'bootstrap/cache/config.php',
        'config/testconfig.php',
    ];

    protected function setUp(): void
    {
        $files = new Filesystem();

        $this->afterApplicationCreated(function () use ($files) {
            $files->ensureDirectoryExists($this->app->configPath());
        });

        $this->beforeApplicationDestroyed(function () use ($files) {
            $files->delete($this->app->configPath('testconfig.php'));
        });

        parent::setUp();
    }

    public function testConfigurationCanBeCachedSuccessfully()
    {
        $files = new Filesystem();
        $files->put(
            $this->app->configPath('testconfig.php'),
            <<<'PHP'
            <?php

            return [
                'string' => 'value',
                'number' => 123,
                'boolean' => true,
                'array' => ['foo', 'bar'],
                'from_env' => env('SOMETHING_FROM_ENV', 10),
                'nested' => [
                    'key' => 'value',
                ],
            ];
            PHP
        );

        $this->artisan('config:cache')
            ->assertSuccessful()
            ->expectsOutputToContain('Configuration cached successfully');

        $this->assertFileExists($this->app->getCachedConfigPath());
    }

    public function testConfigurationCacheFailsWithNonSerializableValue()
    {
        $files = new Filesystem();
        $files->put(
            $this->app->configPath('testconfig.php'),
            <<<'PHP'
            <?php

            return [
                'closure' => function () {
                    return 'test';
                },
            ];
            PHP
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Your configuration files could not be serialized because the value at "testconfig.closure" is non-serializable.');

        $this->artisan('config:cache');
    }

    public function testConfigurationCacheFailsWithNestedNonSerializableValue()
    {
        $files = new Filesystem();
        $files->put(
            $this->app->configPath('testconfig.php'),
            <<<'PHP'
            <?php

            return [
                'nested' => [
                    'deep' => [
                        'closure' => function () {
                            return 'test';
                        },
                    ],
                ],
            ];
            PHP
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Your configuration files could not be serialized because the value at "testconfig.nested.deep.closure" is non-serializable.');

        $this->artisan('config:cache');
    }

    public function testConfigurationCacheIsDeletedWhenSerializationFails()
    {
        $files = new Filesystem();
        $files->put(
            $this->app->configPath('testconfig.php'),
            <<<'PHP'
            <?php

            return [
                'closure' => function () {
                    return 'test';
                },
            ];
            PHP
        );

        try {
            $this->artisan('config:cache');
            $this->fail('should have thrown an exception');
        } catch (LogicException) {
            // Expected exception
        }

        $this->assertFileDoesNotExist($this->app->getCachedConfigPath());
    }

    public function testConfigCacheDoesNotOverwriteGlobalContainerInstance()
    {
        $originalInstance = Container::getInstance();

        $this->artisan('config:cache')
            ->assertSuccessful();

        $this->assertSame($originalInstance, Container::getInstance());
    }

    public function testConfigurationCacheRebuildsFromSourceWhenApplicationBootedWithExistingCachedConfig()
    {
        $files = new Filesystem();

        $files->put(
            $this->app->configPath('testconfig.php'),
            <<<'PHP'
            <?php

            return [
                'value' => 'alpha',
            ];
            PHP
        );

        $this->artisan('config:cache')->assertSuccessful();

        $files->put(
            $this->app->configPath('testconfig.php'),
            <<<'PHP'
            <?php

            return [
                'value' => 'beta',
            ];
            PHP
        );

        $this->artisan('config:cache')->assertSuccessful();

        $cached = require $this->app->getCachedConfigPath();

        $this->assertSame('beta', $cached['testconfig']['value']);
    }
}
