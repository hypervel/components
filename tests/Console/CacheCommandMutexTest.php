<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\CacheCommandMutex;
use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Mockery\MockInterface;

/**
 * @internal
 * @coversNothing
 */
class CacheCommandMutexTest extends TestCase
{
    protected CacheCommandMutex $mutex;

    protected Command $command;

    protected Factory|m\MockInterface $cacheFactory;

    protected Repository|m\MockInterface $cacheRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFactory = m::mock(Factory::class);
        $this->cacheRepository = m::mock(Repository::class);
        $this->mutex = new CacheCommandMutex($this->cacheFactory);
        $this->command = new class extends Command {
            protected ?string $name = 'command-name';
        };
    }

    public function testCanCreateMutex()
    {
        $this->mockUsingCacheStore();
        $this->cacheRepository->shouldReceive('add')
            ->andReturn(true)
            ->once();
        $actual = $this->mutex->create($this->command);

        $this->assertTrue($actual);
    }

    public function testCannotCreateMutexIfAlreadyExist()
    {
        $this->mockUsingCacheStore();
        $this->cacheRepository->shouldReceive('add')
            ->andReturn(false)
            ->once();
        $actual = $this->mutex->create($this->command);

        $this->assertFalse($actual);
    }

    public function testCanCreateMutexWithCustomConnection()
    {
        $this->mockUsingCacheStore();
        $this->cacheRepository->shouldReceive('getStore')
            ->with('test')
            ->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('add')
            ->andReturn(false)
            ->once();
        $this->mutex->useStore('test');

        $this->mutex->create($this->command);
    }

    public function testCanCreateMutexWithLockProvider()
    {
        $lock = $this->mockUsingLockProvider();
        $this->acquireLockExpectations($lock, true);

        $actual = $this->mutex->create($this->command);

        $this->assertTrue($actual);
    }

    public function testCanCreateMutexWithCustomLockProviderConnection()
    {
        $this->mockUsingCacheStore();
        $this->cacheRepository->shouldReceive('getStore')
            ->with('test')
            ->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('add')
            ->andReturn(false)
            ->once();
        $this->mutex->useStore('test');

        $this->mutex->create($this->command);
    }

    public function testCannotCreateMutexIfAlreadyExistWithLockProvider()
    {
        $lock = $this->mockUsingLockProvider();
        $this->acquireLockExpectations($lock, false);
        $actual = $this->mutex->create($this->command);

        $this->assertFalse($actual);
    }

    public function testCanCreateMutexWithCustomConnectionWithLockProvider()
    {
        $lock = m::mock(Store::class, LockProvider::class);
        $this->cacheFactory->expects('store')->once()->with('test')->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->twice()->andReturn($lock);

        $this->acquireLockExpectations($lock, true);
        $this->mutex->useStore('test');

        $this->mutex->create($this->command);
    }

    private function mockUsingCacheStore(): void
    {
        $this->cacheFactory->expects('store')->once()->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
    }

    private function mockUsingLockProvider(): m\MockInterface
    {
        $lock = m::mock(Store::class, LockProvider::class);
        $this->cacheFactory->expects('store')->once()->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->twice()->andReturn($lock);

        return $lock;
    }

    private function acquireLockExpectations(MockInterface $lock, bool $acquiresSuccessfully): void
    {
        $lockInstance = m::mock(Lock::class);
        $lockInstance->expects('get')
            ->once()
            ->andReturns($acquiresSuccessfully);

        $lock->expects('lock')
            ->once()
            ->with(m::type('string'), m::type('int'))
            ->andReturns($lockInstance);
    }

    public function testCommandMutexNameWithoutIsolatedMutexNameMethod()
    {
        $this->mockUsingCacheStore();

        $this->cacheRepository->shouldReceive('getStore')
            ->with('test')
            ->andReturn($this->cacheRepository);

        $this->cacheRepository->shouldReceive('add')
            ->once()
            ->withArgs(function ($key) {
                $this->assertEquals('framework' . DIRECTORY_SEPARATOR . 'command-command-name', $key);

                return true;
            })
            ->andReturn(true);

        $this->mutex->create($this->command);
    }

    public function testCommandMutexNameWithIsolatedMutexNameMethod()
    {
        $command = new class extends Command {
            protected ?string $name = 'command-name';

            public function isolatableId(): string
            {
                return 'isolated';
            }
        };

        $this->mockUsingCacheStore();

        $this->cacheRepository->shouldReceive('getStore')
            ->with('test')
            ->andReturn($this->cacheRepository);

        $this->cacheRepository->shouldReceive('add')
            ->once()
            ->withArgs(function ($key) {
                $this->assertEquals('framework' . DIRECTORY_SEPARATOR . 'command-command-name-isolated', $key);

                return true;
            })
            ->andReturn(true);

        $this->mutex->create($command);
    }
}
