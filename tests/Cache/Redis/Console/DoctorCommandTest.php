<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Console;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Contracts\Factory as CacheContract;
use Hypervel\Cache\Contracts\Repository;
use Hypervel\Cache\Contracts\Store;
use Hypervel\Cache\Redis\Console\DoctorCommand;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Cache\RedisStore;
use Hypervel\Redis\RedisConnection;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tests for the cache:redis-doctor command.
 *
 * @group integration
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class DoctorCommandTest extends TestCase
{
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

        $this->app->set(CacheContract::class, $cacheManager);

        $command = new DoctorCommand();
        $result = $command->run(new ArrayInput(['--store' => 'file']), new NullOutput());

        $this->assertSame(1, $result);
    }

    public function testDoctorDetectsRedisStoreFromConfig(): void
    {
        // Set up config with a redis store
        $config = m::mock(ConfigInterface::class);
        $config->shouldReceive('get')
            ->with('cache.stores', [])
            ->andReturn([
                'file' => ['driver' => 'file'],
                'redis' => ['driver' => 'redis', 'connection' => 'default'],
            ]);
        $config->shouldReceive('get')
            ->with('cache.default', 'file')
            ->andReturn('file');
        $config->shouldReceive('get')
            ->with('cache.stores.redis.connection', 'default')
            ->andReturn('default');

        $this->app->set(ConfigInterface::class, $config);

        // Mock Redis store
        $context = m::mock(StoreContext::class);
        $context->shouldReceive('withConnection')
            ->andReturnUsing(function ($callback) {
                $conn = m::mock(RedisConnection::class);
                $conn->shouldReceive('info')->with('server')->andReturn(['redis_version' => '7.0.0']);
                return $callback($conn);
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

        $this->app->set(CacheContract::class, $cacheManager);

        // The command will fail at environment checks (Redis version check for 'any' mode)
        // but this tests that store detection works
        $command = new DoctorCommand();
        $output = new BufferedOutput();
        $command->run(new ArrayInput([]), $output);

        // Verify it detected the redis store (case-insensitive check)
        $outputText = $output->fetch();
        $this->assertStringContainsString('Redis', $outputText);
        $this->assertStringContainsString('Tag Mode: any', $outputText);
    }

    public function testDoctorUsesSpecifiedStore(): void
    {
        $config = m::mock(ConfigInterface::class);
        $config->shouldReceive('get')
            ->with('cache.default', 'file')
            ->andReturn('file');
        $config->shouldReceive('get')
            ->with('cache.stores.custom-redis.connection', 'default')
            ->andReturn('custom');

        $this->app->set(ConfigInterface::class, $config);

        // Mock Redis store
        $context = m::mock(StoreContext::class);
        $context->shouldReceive('withConnection')
            ->andReturnUsing(function ($callback) {
                $conn = m::mock(RedisConnection::class);
                $conn->shouldReceive('info')->with('server')->andReturn(['redis_version' => '7.0.0']);
                return $callback($conn);
            });

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getTagMode')->andReturn(TagMode::All);
        $store->shouldReceive('getContext')->andReturn($context);
        $store->shouldReceive('getPrefix')->andReturn('cache:');

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        // Should use the specified store name (called multiple times during command)
        $cacheManager->shouldReceive('store')
            ->with('custom-redis')
            ->andReturn($repository);

        $this->app->set(CacheContract::class, $cacheManager);

        $command = new DoctorCommand();
        $output = new BufferedOutput();
        $command->run(new ArrayInput(['--store' => 'custom-redis']), $output);

        // Verify the custom store was used
        $outputText = $output->fetch();
        $this->assertStringContainsString('custom-redis', $outputText);
    }

    public function testDoctorDisplaysTagMode(): void
    {
        $config = m::mock(ConfigInterface::class);
        $config->shouldReceive('get')
            ->with('cache.default', 'file')
            ->andReturn('redis');
        $config->shouldReceive('get')
            ->with('cache.stores.redis.connection', 'default')
            ->andReturn('default');

        $this->app->set(ConfigInterface::class, $config);

        // Mock Redis store with 'all' mode
        $context = m::mock(StoreContext::class);
        $context->shouldReceive('withConnection')
            ->andReturnUsing(function ($callback) {
                $conn = m::mock(RedisConnection::class);
                $conn->shouldReceive('info')->with('server')->andReturn(['redis_version' => '7.0.0']);
                return $callback($conn);
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

        $this->app->set(CacheContract::class, $cacheManager);

        $command = new DoctorCommand();
        $output = new BufferedOutput();
        $command->run(new ArrayInput(['--store' => 'redis']), $output);

        // Verify tag mode is displayed
        $outputText = $output->fetch();
        $this->assertStringContainsString('all', $outputText);
    }

    public function testDoctorFailsWhenNoRedisStoreDetected(): void
    {
        // Set up config with NO redis stores
        $config = m::mock(ConfigInterface::class);
        $config->shouldReceive('get')
            ->with('cache.stores', [])
            ->andReturn([
                'file' => ['driver' => 'file'],
                'array' => ['driver' => 'array'],
            ]);
        $config->shouldReceive('get')
            ->with('cache.default', 'file')
            ->andReturn('file');

        $this->app->set(ConfigInterface::class, $config);

        $command = new DoctorCommand();
        $output = new BufferedOutput();
        $result = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Could not detect', $outputText);
    }

    public function testDoctorDisplaysSystemInformation(): void
    {
        $config = m::mock(ConfigInterface::class);
        $config->shouldReceive('get')
            ->with('cache.stores', [])
            ->andReturn([
                'redis' => ['driver' => 'redis', 'connection' => 'default'],
            ]);
        $config->shouldReceive('get')
            ->with('cache.default', 'file')
            ->andReturn('redis');
        $config->shouldReceive('get')
            ->with('cache.stores.redis.connection', 'default')
            ->andReturn('default');

        $this->app->set(ConfigInterface::class, $config);

        $context = m::mock(StoreContext::class);
        $context->shouldReceive('withConnection')
            ->andReturnUsing(function ($callback) {
                $conn = m::mock(RedisConnection::class);
                $conn->shouldReceive('info')->with('server')->andReturn(['redis_version' => '7.2.4']);
                return $callback($conn);
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

        $this->app->set(CacheContract::class, $cacheManager);

        $command = new DoctorCommand();
        $output = new BufferedOutput();
        $command->run(new ArrayInput([]), $output);

        $outputText = $output->fetch();

        // Verify system information is displayed
        $this->assertStringContainsString('System Information', $outputText);
        $this->assertStringContainsString('PHP Version', $outputText);
        $this->assertStringContainsString('Hypervel', $outputText);
    }
}
