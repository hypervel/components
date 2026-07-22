<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\Integration\Generators\TestCase;
use LogicException;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ConfigCacheCommandTest extends TestCase
{
    protected array $files = [
        'bootstrap/cache/config.php',
        'config/testconfig.php',
    ];

    protected function setUp(): void
    {
        $files = new Filesystem;

        $this->afterApplicationCreated(function () use ($files) {
            $files->ensureDirectoryExists($this->app->configPath());
        });

        $this->beforeApplicationDestroyed(function () use ($files) {
            $files->delete($this->app->configPath('testconfig.php'));
        });

        parent::setUp();
    }

    public function testConfigurationCanBeCachedSuccessfully(): void
    {
        $files = new Filesystem;
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

    public function testConfigurationCacheFailsWithNonSerializableValue(): void
    {
        $files = new Filesystem;
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

    public function testConfigurationCacheFailsWithNestedNonSerializableValue(): void
    {
        $files = new Filesystem;
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

    public function testExistingConfigurationCacheSurvivesSerializationFailure(): void
    {
        $files = new Filesystem;
        $cachePath = $this->app->getCachedConfigPath();
        $previousContents = "<?php return ['previous' => true];\n";
        $files->put($cachePath, $previousContents);
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
        }

        $this->assertSame($previousContents, $files->get($cachePath));
    }

    public function testConfigCacheDoesNotOverwriteGlobalContainerInstance(): void
    {
        $originalInstance = Container::getInstance();

        $this->artisan('config:cache')
            ->assertSuccessful();

        $this->assertSame($originalInstance, Container::getInstance());
    }

    public function testConfigurationCacheRebuildsFromSourceWhenApplicationBootedWithExistingCachedConfig(): void
    {
        $files = new Filesystem;

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

    public function testConfigCacheSubprocessUsesTheParentsResolvedCachePath(): void
    {
        $files = new Filesystem;
        $previousCachePath = $_SERVER['APP_CONFIG_CACHE'] ?? null;
        $hadCachePath = array_key_exists('APP_CONFIG_CACHE', $_SERVER);
        $defaultCachePath = $this->app->bootstrapPath('cache/config.php');
        $alternateCachePath = $this->app->bootstrapPath('cache/config-alternate.php');
        $this->files[] = 'bootstrap/cache/config-alternate.php';
        $files->put($defaultCachePath, "<?php return ['stale' => true];\n");
        $files->put(
            $this->app->configPath('testconfig.php'),
            "<?php return ['value' => 'source'];\n",
        );
        $_SERVER['APP_CONFIG_CACHE'] = $alternateCachePath;

        try {
            $this->artisan('config:cache')->assertSuccessful();

            $cached = require $alternateCachePath;
            $this->assertSame('source', $cached['testconfig']['value']);
            $this->assertArrayNotHasKey('stale', $cached);
        } finally {
            if ($hadCachePath) {
                $_SERVER['APP_CONFIG_CACHE'] = $previousCachePath;
            } else {
                unset($_SERVER['APP_CONFIG_CACHE']);
            }
        }
    }

    public function testExistingConfigurationCacheSurvivesChildBootstrapFailure(): void
    {
        $files = new Filesystem;
        $cachePath = $this->app->getCachedConfigPath();
        $previousContents = "<?php return ['previous' => true];\n";
        $files->put($cachePath, $previousContents);
        $files->put(
            $this->app->configPath('testconfig.php'),
            "<?php throw new RuntimeException('bootstrap failed');\n",
        );

        try {
            $this->artisan('config:cache');
            $this->fail('The subprocess should have failed.');
        } catch (ProcessFailedException) {
        }

        $this->assertSame($previousContents, $files->get($cachePath));
    }

    public function testConfigurationCacheReplacementPreservesExistingMode(): void
    {
        $files = new Filesystem;
        $cachePath = $this->app->getCachedConfigPath();
        $files->put($cachePath, "<?php return ['previous' => true];\n");
        chmod($cachePath, 0640);
        $files->put(
            $this->app->configPath('testconfig.php'),
            "<?php return ['value' => 'fresh'];\n",
        );

        $this->artisan('config:cache')->assertSuccessful();

        $this->assertSame(0640, fileperms($cachePath) & 0777);
        $this->assertSame('fresh', (require $cachePath)['testconfig']['value']);
    }

    public function testExistingConfigurationCacheSurvivesPublicationFailure(): void
    {
        $files = new Filesystem;
        $cachePath = $this->app->getCachedConfigPath();
        $previousContents = "<?php return ['previous' => true];\n";
        $files->put($cachePath, $previousContents);
        chmod($cachePath, 0640);
        $files->put(
            $this->app->configPath('testconfig.php'),
            "<?php return ['value' => 'fresh'];\n",
        );

        $publicationException = new RuntimeException('publication failed');
        $mock = m::mock(Filesystem::class)->makePartial();
        $mock->shouldReceive('replace')
            ->once()
            ->with($cachePath, m::type('string'), 0640)
            ->andThrow($publicationException);
        $this->app->instance(Filesystem::class, $mock);

        try {
            $this->artisan('config:cache');
            $this->fail('Publication should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertSame($previousContents, $files->get($cachePath));
        $this->assertSame(0640, fileperms($cachePath) & 0777);
    }
}
