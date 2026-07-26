<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Console;

use Closure;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Redis\Console\Doctor\Checks\HashFieldExpirationCheck;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;
use Hypervel\Cache\Redis\Console\DoctorCommand;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TagMode;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Cache\Factory as CacheContract;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConnection;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tests for the cache:redis-doctor command.
 */
class DoctorCommandTest extends TestCase
{
    public function testHashFieldExpirationCheckSkipsAllMode(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldNotReceive('hsetex');
        $connection->shouldNotReceive('hexpire');
        $connection->shouldNotReceive('del');

        $check = new HashFieldExpirationCheck($connection, 'all');
        $result = $check->run();

        $this->assertTrue($result->passed());
        $this->assertSame([
            [
                'passed' => true,
                'description' => 'Hash-field expiration check skipped (not required for all mode)',
            ],
        ], $result->assertions);
        $this->assertNull($check->getFixInstructions());
    }

    public function testHashFieldExpirationCheckProbesHsetexAndHexpireInAnyMode(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('hsetex')
            ->once()
            ->with(m::pattern('/^erc:doctor:hash-field-expiration-test:/'), ['field' => '1'], ['EX' => 60])
            ->andReturn(true);
        $connection->shouldReceive('hexpire')
            ->once()
            ->with(m::pattern('/^erc:doctor:hash-field-expiration-test:/'), 60, ['field'])
            ->andReturn([1]);
        $connection->shouldReceive('del')
            ->once()
            ->with(m::pattern('/^erc:doctor:hash-field-expiration-test:/'))
            ->andReturn(1);

        $check = new HashFieldExpirationCheck($connection, 'any');
        $result = $check->run();

        $this->assertTrue($result->passed());
        $this->assertSame([
            [
                'passed' => true,
                'description' => 'HSETEX and HEXPIRE commands are available',
            ],
        ], $result->assertions);
        $this->assertNull($check->getFixInstructions());
    }

    public function testHashFieldExpirationCheckReportsFixInstructionsWhenProbeFails(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('hsetex')
            ->once()
            ->with(m::pattern('/^erc:doctor:hash-field-expiration-test:/'), ['field' => '1'], ['EX' => 60])
            ->andThrow(new RuntimeException('unsupported command'));
        $connection->shouldReceive('hexpire')->never();
        $connection->shouldReceive('del')
            ->once()
            ->with(m::pattern('/^erc:doctor:hash-field-expiration-test:/'))
            ->andReturn(0);

        $check = new HashFieldExpirationCheck($connection, 'any');
        $result = $check->run();

        $this->assertFalse($result->passed());
        $this->assertSame([
            [
                'passed' => false,
                'description' => 'HSETEX and HEXPIRE commands are available',
            ],
        ], $result->assertions);
        $this->assertSame(
            'Any tagging mode requires Redis 8.0+ or Valkey 9.0+ for hash-field expiration commands such as HSETEX and HEXPIRE. Upgrade your Redis/Valkey server, or switch to all tagging mode.',
            $check->getFixInstructions()
        );
    }

    public function testHashFieldExpirationCheckReportsFailureWhenProbeReturnsFalse(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('hsetex')
            ->once()
            ->with(m::pattern('/^erc:doctor:hash-field-expiration-test:/'), ['field' => '1'], ['EX' => 60])
            ->andReturn(false);
        $connection->shouldReceive('hexpire')
            ->once()
            ->with(m::pattern('/^erc:doctor:hash-field-expiration-test:/'), 60, ['field'])
            ->andReturn(false);
        $connection->shouldReceive('del')
            ->once()
            ->with(m::pattern('/^erc:doctor:hash-field-expiration-test:/'))
            ->andReturn(0);

        $check = new HashFieldExpirationCheck($connection, 'any');
        $result = $check->run();

        $this->assertFalse($result->passed());
        $this->assertSame([
            [
                'passed' => false,
                'description' => 'HSETEX and HEXPIRE commands are available',
            ],
        ], $result->assertions);
        $this->assertSame(
            'Any tagging mode requires Redis 8.0+ or Valkey 9.0+ for hash-field expiration commands such as HSETEX and HEXPIRE. Upgrade your Redis/Valkey server, or switch to all tagging mode.',
            $check->getFixInstructions()
        );
    }

    public function testDoctorFailsForNonRedisStore(): void
    {
        $nonRedisStore = m::mock(Store::class);

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')
            ->andReturn($nonRedisStore);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('file')
            ->andReturn($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new DoctorCommand;
        $command->setHypervel($this->app);
        $result = $command->run(new ArrayInput(['--store' => 'file']), new NullOutput);

        $this->assertSame(1, $result);
    }

    public function testDoctorDetectsRedisStoreFromConfig(): void
    {
        // Set up config with a redis store
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('array')
            ->with('cache.stores')
            ->andReturn([
                'file' => ['driver' => 'file'],
                'redis' => ['driver' => 'redis', 'connection' => 'default'],
            ]);
        $config->shouldReceive('string')
            ->with('cache.default')
            ->andReturn('file');
        $config->shouldReceive('get')
            ->with('cache.stores.redis.connection', 'default')
            ->andReturn('default');

        $this->app->instance('config', $config);

        // Mock Redis store
        $context = m::mock(StoreContext::class);
        $context->shouldReceive('withConnection')
            ->andReturnUsing(function ($callback) {
                $connection = m::mock(PhpRedisConnection::class);
                $connection->shouldReceive('info')->with('server')->andReturn(['redis_version' => '7.0.0']);
                return $callback($connection);
            });

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getTagMode')->andReturn(TagMode::Any);
        $store->shouldReceive('getContext')->andReturn($context);
        $store->shouldReceive('getPrefix')->andReturn('cache:');

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('redis')
            ->andReturn($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        // The command will fail at environment checks (Redis version check for 'any' mode)
        // but this tests that store detection works
        $command = new DoctorCommand;
        $command->setHypervel($this->app);
        $output = new BufferedOutput;
        $command->run(new ArrayInput(['--store' => '']), $output);

        // Verify it detected the redis store (case-insensitive check)
        $outputText = $output->fetch();
        $this->assertStringContainsString('Redis', $outputText);
        $this->assertStringContainsString('Tag Mode: any', $outputText);
    }

    public function testDoctorUsesZeroNamedStore(): void
    {
        if (! extension_loaded('redis')
            || ! version_compare(phpversion('redis'), '6.3.0', '>=')) {
            $this->markTestSkipped(
                'Redis extension >= 6.3.0 is required for this test.'
            );
            return;
        }

        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')
            ->with('cache.default')
            ->andReturn('file');
        $config->shouldReceive('get')
            ->with('cache.stores.0.connection', 'default')
            ->andReturn('custom');

        $this->app->instance('config', $config);

        // Mock Redis store
        $context = m::mock(StoreContext::class);
        $context->shouldReceive('withConnection')
            ->andReturnUsing(function ($callback) {
                $connection = m::mock(PhpRedisConnection::class);
                $connection->shouldReceive('info')->with('server')->andReturn(['redis_version' => '7.0.0']);
                return $callback($connection);
            });

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getTagMode')->andReturn(TagMode::All);
        $store->shouldReceive('getContext')->andReturn($context);
        $store->shouldReceive('getPrefix')->andReturn('cache:');

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('0')
            ->andReturn($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        // Skip functional checks — this test only verifies store routing
        $command = new class extends DoctorCommand {
            protected function getFunctionalChecks(): array
            {
                return [];
            }

            protected function cleanup(DoctorContext $context, bool $silent = false): void
            {
            }

            protected function runCleanupVerification(DoctorContext $context): void
            {
            }
        };
        $command->setHypervel($this->app);
        $output = new BufferedOutput;
        $command->run(new ArrayInput(['--store' => '0']), $output);

        $outputText = $output->fetch();
        $this->assertStringContainsString('Testing cache store: 0', $outputText);
    }

    public function testDoctorRunsChecksWhileRedisConnectionIsBorrowed(): void
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')
            ->with('cache.default')
            ->andReturn('redis');

        $this->app->instance('config', $config);

        $borrowedConnection = false;
        $assertConnectionBorrowed = function () use (&$borrowedConnection): void {
            $this->assertTrue($borrowedConnection);
        };

        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('info')
            ->once()
            ->with('server')
            ->andReturn(['redis_version' => '7.0.0']);

        $context = m::mock(StoreContext::class);
        $context->shouldReceive('withConnection')
            ->twice()
            ->andReturnUsing(function (callable $callback) use (&$borrowedConnection, $connection) {
                $borrowedConnection = true;

                try {
                    return $callback($connection);
                } finally {
                    $borrowedConnection = false;
                }
            });

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getTagMode')->andReturn(TagMode::Any);
        $store->shouldReceive('getContext')->andReturn($context);
        $store->shouldReceive('getPrefix')->andReturn('cache:');

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('redis')
            ->andReturn($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new class($assertConnectionBorrowed) extends DoctorCommand {
            public function __construct(private readonly Closure $assertConnectionBorrowed)
            {
                parent::__construct();
            }

            protected function runEnvironmentChecks(
                string $storeName,
                RedisStore $store,
                string $taggingMode,
                RedisConnection $redis
            ): bool {
                ($this->assertConnectionBorrowed)();

                return true;
            }

            protected function runFunctionalChecks(DoctorContext $context): void
            {
                ($this->assertConnectionBorrowed)();
            }

            protected function runCleanupVerification(DoctorContext $context): void
            {
                ($this->assertConnectionBorrowed)();
            }

            protected function cleanup(DoctorContext $context, bool $silent = false): void
            {
                ($this->assertConnectionBorrowed)();
            }
        };

        $command->setHypervel($this->app);
        $result = $command->run(new ArrayInput(['--store' => 'redis']), new BufferedOutput);

        $this->assertSame(0, $result);
    }

    public function testDoctorDisplaysTagMode(): void
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')
            ->with('cache.default')
            ->andReturn('redis');
        $config->shouldReceive('get')
            ->with('cache.stores.redis.connection', 'default')
            ->andReturn('default');

        $this->app->instance('config', $config);

        // Mock Redis store with 'all' mode
        $context = m::mock(StoreContext::class);
        $context->shouldReceive('withConnection')
            ->andReturnUsing(function ($callback) {
                $connection = m::mock(PhpRedisConnection::class);
                $connection->shouldReceive('info')->with('server')->andReturn(['redis_version' => '7.0.0']);
                return $callback($connection);
            });

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getTagMode')->andReturn(TagMode::All);
        $store->shouldReceive('getContext')->andReturn($context);
        $store->shouldReceive('getPrefix')->andReturn('cache:');

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('redis')
            ->andReturn($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        // Skip functional checks — this test only verifies tag mode display
        $command = new class extends DoctorCommand {
            protected function getFunctionalChecks(): array
            {
                return [];
            }

            protected function cleanup(DoctorContext $context, bool $silent = false): void
            {
            }

            protected function runCleanupVerification(DoctorContext $context): void
            {
            }
        };
        $command->setHypervel($this->app);
        $output = new BufferedOutput;
        $command->run(new ArrayInput(['--store' => 'redis']), $output);

        // Verify tag mode is displayed
        $outputText = $output->fetch();
        $this->assertStringContainsString('all', $outputText);
    }

    public function testDoctorFailsWhenNoRedisStoreDetected(): void
    {
        // Set up config with NO redis stores
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('array')
            ->with('cache.stores')
            ->andReturn([
                'file' => ['driver' => 'file'],
                'array' => ['driver' => 'array'],
            ]);
        $config->shouldReceive('string')
            ->with('cache.default')
            ->andReturn('file');

        $this->app->instance('config', $config);

        $command = new DoctorCommand;
        $command->setHypervel($this->app);
        $output = new BufferedOutput;
        $result = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Could not detect', $outputText);
    }

    public function testDoctorDisplaysSystemInformation(): void
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('array')
            ->with('cache.stores')
            ->andReturn([
                'redis' => ['driver' => 'redis', 'connection' => 'default'],
            ]);
        $config->shouldReceive('string')
            ->with('cache.default')
            ->andReturn('redis');
        $config->shouldReceive('get')
            ->with('cache.stores.redis.connection', 'default')
            ->andReturn('default');

        $this->app->instance('config', $config);

        $context = m::mock(StoreContext::class);
        $context->shouldReceive('withConnection')
            ->andReturnUsing(function ($callback) {
                $connection = m::mock(PhpRedisConnection::class);
                $connection->shouldReceive('info')->with('server')->andReturn(['redis_version' => '7.2.4']);
                return $callback($connection);
            });

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getTagMode')->andReturn(TagMode::Any);
        $store->shouldReceive('getContext')->andReturn($context);
        $store->shouldReceive('getPrefix')->andReturn('cache:');

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('redis')
            ->andReturn($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new DoctorCommand;
        $command->setHypervel($this->app);
        $output = new BufferedOutput;
        $command->run(new ArrayInput([]), $output);

        $outputText = $output->fetch();

        // Verify system information is displayed
        $this->assertStringContainsString('System Information', $outputText);
        $this->assertStringContainsString('PHP Version', $outputText);
        $this->assertStringContainsString('Hypervel', $outputText);
    }
}
