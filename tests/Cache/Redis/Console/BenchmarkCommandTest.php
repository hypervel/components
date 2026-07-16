<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Console;

use Hypervel\Cache\Redis\Console\BenchmarkCommand;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Factory as CacheContract;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

class BenchmarkCommandTest extends TestCase
{
    public function testSetupPreservesZeroStoreOption(): void
    {
        $this->mockCacheStore('0');

        $command = $this->createCommand();

        $this->assertSame(Command::SUCCESS, $command->run(
            new ArrayInput(['--store' => '0']),
            new NullOutput,
        ));
        $this->assertSame('0', $command->storeName());
    }

    public function testSetupDetectsStoreForEmptyOption(): void
    {
        $this->app->make('config')->set('cache.stores', [
            'redis' => ['driver' => 'redis'],
        ]);
        $this->mockCacheStore('redis');

        $command = $this->createCommand();

        $this->assertSame(Command::SUCCESS, $command->run(
            new ArrayInput(['--store' => '']),
            new NullOutput,
        ));
        $this->assertSame('redis', $command->storeName());
    }

    public function testSetupCastsDetectedNumericStoreNameToString(): void
    {
        $this->app->make('config')->set('cache.stores', [
            0 => ['driver' => 'redis'],
        ]);
        $this->mockCacheStore('0');

        $command = $this->createCommand();

        $this->assertSame(Command::SUCCESS, $command->run(
            new ArrayInput([]),
            new NullOutput,
        ));
        $this->assertSame('0', $command->storeName());
    }

    public function testSetupFailsGracefullyWhenNoRedisStoreCanBeDetected(): void
    {
        $this->app->make('config')->set('cache.stores', [
            'file' => ['driver' => 'file'],
        ]);

        $cache = m::mock(CacheContract::class);
        $cache->shouldNotReceive('store');
        $this->app->instance(CacheContract::class, $cache);

        $command = $this->createCommand();
        $output = new BufferedOutput;

        $this->assertSame(Command::FAILURE, $command->run(new ArrayInput([]), $output));
        $this->assertStringContainsString(
            'Could not detect a cache store using the "redis" driver.',
            $output->fetch(),
        );
    }

    private function mockCacheStore(string $name): void
    {
        $store = m::mock(RedisStore::class);

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')->once()->andReturn($store);
        $repository->shouldReceive('get')->once()->with('test')->andReturnNull();

        $cache = m::mock(CacheContract::class);
        $cache->shouldReceive('store')->twice()->with($name)->andReturn($repository);

        $this->app->instance(CacheContract::class, $cache);
    }

    private function createCommand(): TestableBenchmarkCommand
    {
        $command = new TestableBenchmarkCommand;
        $command->setHypervel($this->app);

        return $command;
    }
}

class TestableBenchmarkCommand extends BenchmarkCommand
{
    public function handle(): int
    {
        return $this->setup() ? self::SUCCESS : self::FAILURE;
    }

    public function storeName(): string
    {
        return $this->storeName;
    }
}
