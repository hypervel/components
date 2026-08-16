<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Redis\RedisConfig;
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

    public function testLoggingConfigReadsDailyRetentionAsAnInteger(): void
    {
        $config = $this->withEnvironmentValue('LOG_DAILY_DAYS', '30', function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/logging.php';
        });

        $this->assertSame(30, $config['channels']['daily']['days']);
    }

    public function testLoggingConfigNormalizesNullablePapertrailPort(): void
    {
        $config = $this->withEnvironmentValue('PAPERTRAIL_PORT', '514', function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/logging.php';
        });

        $this->assertSame(514, $config['channels']['papertrail']['handler_with']['port']);

        $config = $this->withEnvironmentValue('PAPERTRAIL_PORT', null, function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/logging.php';
        });

        $this->assertNull($config['channels']['papertrail']['handler_with']['port']);
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

    public function testShippedCacheStoresUseTheirDriverEventDefaults(): void
    {
        $this->assertNotNull($this->app->make('cache')->store('array')->getEventDispatcher());
        $this->assertNull($this->app->make('cache')->store('failover')->getEventDispatcher());
    }

    public function testQueueConfigDeclaresConnectionPolicyAndOmitsAdvancedDefaults(): void
    {
        $config = $this->withEnvironmentValue(
            'BEANSTALKD_QUEUE_PORT',
            '11400',
            fn (): array => $this->withEnvironmentValue(
                'AWS_SESSION_TOKEN',
                'session-token',
                fn (): array => $this->queueConfig(),
            ),
        );
        $connections = $config['connections'];

        $this->assertFalse($connections['sync']['after_commit']);
        $this->assertFalse($connections['database']['after_commit']);

        foreach (['background', 'deferred', 'beanstalkd', 'sqs', 'redis', 'failover'] as $connection) {
            $this->assertTrue($connections[$connection]['after_commit']);
        }

        $this->assertSame(11400, $connections['beanstalkd']['port']);
        $this->assertArrayNotHasKey('timeout', $connections['beanstalkd']);
        $this->assertSame('session-token', $connections['sqs']['token']);
        $this->assertArrayNotHasKey('credentials', $connections['sqs']);
        $this->assertArrayNotHasKey('version', $connections['sqs']);
        $this->assertArrayNotHasKey('http', $connections['sqs']);
        $this->assertArrayNotHasKey('migration_batch_size', $connections['redis']);
        $this->assertSame(['database', 'deferred'], $connections['failover']['connections']);
    }

    public function testQueueConfigNormalizesOverflowFlags(): void
    {
        $config = $this->withEnvironmentValues([
            'SQS_OVERFLOW_ENABLED' => '1',
            'SQS_OVERFLOW_FLUSH_ON_CLEAR' => '0',
        ], fn (): array => $this->queueConfig());

        $this->assertTrue($config['connections']['sqs']['overflow']['enabled']);
        $this->assertFalse($config['connections']['sqs']['overflow']['flush_on_clear']);
    }

    public function testShippedRedisConnectionsDeclareRequiredMembersAndUseOptionalDefaults(): void
    {
        $requiredMembers = [
            'url',
            'host',
            'username',
            'password',
            'port',
            'database',
            'max_retries',
            'backoff_algorithm',
            'backoff_base',
            'backoff_cap',
            'pool',
        ];
        $omittedMembers = [
            'scheme',
            'name',
            'timeout',
            'retry_interval',
            'read_timeout',
            'context',
            'options',
            'prefix',
            'events',
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
            $this->assertSame([], array_intersect($omittedMembers, array_keys($connection)));

            $effectiveConnection = $redisConfig->connectionConfig($name);

            $this->assertNull($effectiveConnection['name']);
            $this->assertNull($effectiveConnection['timeout']);
            $this->assertNull($effectiveConnection['prefix']);
            $this->assertFalse($effectiveConnection['events']);
            $this->assertSame(
                $sharedPrefix,
                $effectiveConnection['options']['prefix'],
            );
        }
    }

    public function testShippedFilesystemDisksDeclareVisibilityAndFailurePolicy(): void
    {
        $disks = $this->filesystemConfig()['disks'];

        foreach ([
            'local' => 'private',
            'public' => 'public',
            's3' => 'public',
            'gcs' => 'public',
        ] as $name => $visibility) {
            $this->assertSame($visibility, $disks[$name]['visibility']);
            $this->assertFalse($disks[$name]['throw']);
            $this->assertFalse($disks[$name]['report']);
        }
    }

    public function testS3RootReadsTheAwsRootEnvironmentVariable(): void
    {
        $config = $this->withEnvironmentValue(
            'AWS_ROOT',
            'application-files',
            fn (): array => $this->filesystemConfig(),
        );

        $this->assertSame('application-files', $config['disks']['s3']['root']);
    }

    public function testFilesystemConfigNormalizesPathStyleEndpointFlag(): void
    {
        $config = $this->withEnvironmentValue(
            'AWS_USE_PATH_STYLE_ENDPOINT',
            '1',
            fn (): array => $this->filesystemConfig(),
        );

        $this->assertTrue($config['disks']['s3']['use_path_style_endpoint']);
    }

    public function testDatabaseConfigNormalizesConnectionPortsAndForeignKeyFlag(): void
    {
        $config = $this->withEnvironmentValues([
            'DB_FOREIGN_KEYS' => '0',
            'DB_PORT' => '15432',
            'DB_POOLED_PORT' => '16432',
        ], function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/database.php';
        });

        $this->assertFalse($config['connections']['sqlite']['foreign_key_constraints']);
        $this->assertSame(15432, $config['connections']['mysql']['port']);
        $this->assertSame(15432, $config['connections']['mariadb']['port']);
        $this->assertSame(15432, $config['connections']['pgsql']['port']);
        $this->assertSame(16432, $config['connections']['pgsql-pooled']['port']);
    }

    public function testHashingConfigNormalizesAlgorithmOptions(): void
    {
        $config = $this->withEnvironmentValues([
            'BCRYPT_ROUNDS' => '13',
            'BCRYPT_LIMIT' => '72',
            'HASH_VERIFY' => '0',
            'ARGON_MEMORY' => '32768',
            'ARGON_THREADS' => '2',
            'ARGON_TIME' => '3',
        ], function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/hashing.php';
        });

        $this->assertSame(13, $config['bcrypt']['rounds']);
        $this->assertSame(72, $config['bcrypt']['limit']);
        $this->assertFalse($config['bcrypt']['verify']);
        $this->assertSame(32768, $config['argon']['memory']);
        $this->assertSame(2, $config['argon']['threads']);
        $this->assertSame(3, $config['argon']['time']);
        $this->assertFalse($config['argon']['verify']);

        $config = $this->withEnvironmentValue('BCRYPT_LIMIT', null, function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/hashing.php';
        });

        $this->assertNull($config['bcrypt']['limit']);
    }

    public function testMailConfigNormalizesSmtpPort(): void
    {
        $config = $this->withEnvironmentValue('MAIL_PORT', '1025', function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/mail.php';
        });

        $this->assertSame(1025, $config['mailers']['smtp']['port']);
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
        $this->assertFalse($config['connections']['pusher']['jsonp']);
        $this->assertArrayNotHasKey('pool', $config['connections']['pusher']);
        $this->assertArrayNotHasKey('pool', $config['connections']['ably']);
    }

    public function testBroadcastingConfigNormalizesConnectionPorts(): void
    {
        $config = $this->withEnvironmentValues([
            'REVERB_PORT' => '8443',
            'PUSHER_PORT' => '9443',
        ], function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/broadcasting.php';
        });

        $this->assertSame(8443, $config['connections']['reverb']['options']['port']);
        $this->assertSame(9443, $config['connections']['pusher']['options']['port']);
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
     * Load the filesystem configuration.
     */
    protected function filesystemConfig(): array
    {
        return require dirname(__DIR__, 2) . '/src/foundation/config/filesystems.php';
    }

    /**
     * Load the queue configuration.
     */
    protected function queueConfig(): array
    {
        return require dirname(__DIR__, 2) . '/src/foundation/config/queue.php';
    }

    /**
     * Load the server configuration.
     */
    protected function serverConfig(): array
    {
        return require dirname(__DIR__, 2) . '/src/foundation/config/server.php';
    }
}
