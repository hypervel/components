<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Redis\RedisConfig;
use Hypervel\Support\Env;
use Hypervel\Testbench\TestCase;
use Swoole\Constant;

class FoundationConfigTest extends TestCase
{
    public function testAppConfigReadsForceHttpsFromForceHttpsEnvironmentVariable(): void
    {
        $config = $this->appConfigWithEnvironment('FORCE_HTTPS', 'true');

        $this->assertTrue($config['force_https']);
    }

    public function testAppConfigReadsStdoutLogFormatFromStdoutLogFormatEnvironmentVariable(): void
    {
        $config = $this->appConfigWithEnvironment('STDOUT_LOG_FORMAT', 'json');

        $this->assertSame('json', $config['stdout_log']['format']);
    }

    public function testAppConfigTreatsNullPreviousKeysAsAnEmptyList(): void
    {
        $config = $this->appConfigWithEnvironment('APP_PREVIOUS_KEYS', '(null)');

        $this->assertSame([], $config['previous_keys']);
    }

    public function testCacheConfigReadsTheScheduleCacheStoreEnvironmentVariable(): void
    {
        $config = $this->withEnvironmentValue(
            'SCHEDULE_CACHE_STORE',
            'scheduling',
            fn (): array => $this->cacheConfig(),
        );

        $this->assertSame('scheduling', $config['schedule_store']);
    }

    public function testCacheConfigIgnoresTheLegacyScheduleCacheDriverEnvironmentVariable(): void
    {
        $config = $this->withEnvironmentValue(
            'SCHEDULE_CACHE_DRIVER',
            'legacy',
            fn (): array => $this->withEnvironmentValue(
                'SCHEDULE_CACHE_STORE',
                null,
                fn (): array => $this->cacheConfig(),
            ),
        );

        $this->assertNull($config['schedule_store']);
    }

    public function testShippedCacheStoreEnablesRepositoryEvents(): void
    {
        $this->assertTrue(config()->boolean('cache.stores.array.events'));
        $this->assertNotNull($this->app->make('cache')->store('array')->getEventDispatcher());
    }

    public function testShippedRedisConnectionsUseTheCompleteStandaloneSchema(): void
    {
        $requiredMembers = [
            'url',
            'scheme',
            'host',
            'username',
            'password',
            'port',
            'database',
            'name',
            'timeout',
            'retry_interval',
            'read_timeout',
            'context',
            'options',
            'prefix',
            'events',
            'max_retries',
            'backoff_algorithm',
            'backoff_base',
            'backoff_cap',
            'pool',
        ];
        $requiredPoolMembers = [
            'min_connections',
            'max_connections',
            'connect_timeout',
            'wait_timeout',
            'heartbeat',
            'heartbeat_timeout',
            'max_idle_time',
            'max_lifetime',
        ];
        $redisConfig = $this->app->make(RedisConfig::class);
        $sharedPrefix = config()->string('database.redis.options.prefix');

        foreach (['default', 'cache', 'session', 'queue', 'reverb'] as $name) {
            $connection = config()->array("database.redis.{$name}");

            $this->assertSame([], array_diff($requiredMembers, array_keys($connection)));
            $this->assertSame([], array_diff($requiredPoolMembers, array_keys($connection['pool'])));
            $this->assertNull($connection['name']);
            $this->assertNull($connection['timeout']);
            $this->assertNull($connection['prefix']);
            $this->assertFalse($connection['events']);
            $this->assertSame(
                $sharedPrefix,
                $redisConfig->connectionConfig($name)['options']['prefix'],
            );
        }
    }

    public function testServerConfigUsesSafeTaskDefaults(): void
    {
        $config = $this->serverConfig();

        $this->assertFalse($config['settings'][Constant::OPTION_TASK_ENABLE_COROUTINE]);
        $this->assertSame(0, $config['settings'][Constant::OPTION_TASK_WORKER_NUM]);
        $this->assertFalse($config['settings'][Constant::OPTION_DAEMONIZE]);
    }

    public function testServerConfigReadsWorkerCountAsAnInteger(): void
    {
        $config = $this->withEnvironmentValue(
            'SERVER_WORKERS',
            '12',
            fn (): array => $this->serverConfig(),
        );

        $this->assertSame(12, $config['settings'][Constant::OPTION_WORKER_NUM]);
    }

    public function testServerConfigReadsMaxWaitTimeAsAnInteger(): void
    {
        $config = $this->withEnvironmentValue(
            'SERVER_MAX_WAIT_TIME',
            '15',
            fn (): array => $this->serverConfig(),
        );

        $this->assertSame(15, $config['settings'][Constant::OPTION_MAX_WAIT_TIME]);
    }

    public function testReverbBroadcastingConfigUsesTheServerPath(): void
    {
        $config = $this->withEnvironmentValue('REVERB_SERVER_PATH', '/socket', function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/broadcasting.php';
        });

        $this->assertSame('/socket', $config['connections']['reverb']['options']['path']);
    }

    public function testBroadcastingConfigDisablesJsonpAndDoesNotShipSdkPools(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/foundation/config/broadcasting.php';

        $this->assertFalse($config['connections']['reverb']['jsonp']);
        $this->assertFalse($config['connections']['reverb']['log']);
        $this->assertFalse($config['connections']['pusher']['jsonp']);
        $this->assertFalse($config['connections']['pusher']['log']);
        $this->assertArrayNotHasKey('pool', $config['connections']['pusher']);
        $this->assertArrayNotHasKey('pool', $config['connections']['ably']);
    }

    public function testViewCompiledPathFallsBackToStoragePathWhenDirectoryDoesNotExist(): void
    {
        $key = 'VIEW_COMPILED_PATH';
        $originalContainer = Container::getInstance();

        try {
            $this->withEnvironmentValue($key, null, function (): void {
                $app = new Application(dirname(__DIR__, 2));
                $app->useStoragePath(sys_get_temp_dir() . '/hypervel-view-config-' . bin2hex(random_bytes(8)));
                Container::setInstance($app);

                $compiledPath = $app->storagePath('framework/views');

                $this->assertDirectoryDoesNotExist($compiledPath);

                $config = require dirname(__DIR__, 2) . '/src/foundation/config/view.php';

                $this->assertSame($compiledPath, $config['compiled']);
            });
        } finally {
            Container::setInstance($originalContainer);
        }
    }

    public function testViewConfigDefinesCompilerDefaults(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/foundation/config/view.php';

        $this->assertFalse($config['relative_hash']);
        $this->assertTrue($config['cache']);
        $this->assertSame('php', $config['compiled_extension']);
        $this->assertTrue($config['check_cache_timestamps']);
    }

    /**
     * Load the application config with one environment override.
     */
    protected function appConfigWithEnvironment(string $key, string $value): array
    {
        return $this->withEnvironmentValue($key, $value, function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/app.php';
        });
    }

    /**
     * Load the cache configuration.
     */
    protected function cacheConfig(): array
    {
        return require dirname(__DIR__, 2) . '/src/foundation/config/cache.php';
    }

    /**
     * Load the server configuration.
     */
    protected function serverConfig(): array
    {
        return require dirname(__DIR__, 2) . '/src/foundation/config/server.php';
    }

    /**
     * Run a callback with a temporary environment variable value.
     */
    protected function withEnvironmentValue(string $key, ?string $value, Closure $callback): mixed
    {
        $originalPutenv = getenv($key);
        $originalServerExists = array_key_exists($key, $_SERVER);
        $originalServer = $_SERVER[$key] ?? null;
        $originalEnvExists = array_key_exists($key, $_ENV);
        $originalEnv = $_ENV[$key] ?? null;

        try {
            $value === null
                ? $this->unsetEnvironmentValue($key)
                : $this->setEnvironmentValue($key, $value);

            return $callback();
        } finally {
            $originalPutenv === false
                ? putenv($key)
                : putenv("{$key}={$originalPutenv}");

            if ($originalServerExists) {
                $_SERVER[$key] = $originalServer;
            } else {
                unset($_SERVER[$key]);
            }

            if ($originalEnvExists) {
                $_ENV[$key] = $originalEnv;
            } else {
                unset($_ENV[$key]);
            }

            Env::flushRepository();
        }
    }
}
