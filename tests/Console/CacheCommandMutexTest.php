<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Cache\ArrayStore;
use Hypervel\Console\CacheCommandMutex;
use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Mockery\MockInterface;

class CacheCommandMutexTest extends TestCase
{
    protected CacheCommandMutex $mutex;

    protected Command $command;

    protected Factory&MockInterface $cacheFactory;

    protected Repository&MockInterface $cacheRepository;

    /**
     * Set up the command mutex and its dependencies.
     */
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

    public function testCanCreateMutex(): void
    {
        $this->mockUsingCacheStore();
        $this->cacheRepository->expects('add')->andReturn(true);
        $actual = $this->mutex->create($this->command);

        $this->assertTrue($actual);
    }

    public function testCannotCreateMutexIfAlreadyExist(): void
    {
        $this->mockUsingCacheStore();
        $this->cacheRepository->expects('add')->andReturn(false);
        $actual = $this->mutex->create($this->command);

        $this->assertFalse($actual);
    }

    public function testCanCreateMutexWithCustomConnection(): void
    {
        $this->mockUsingCacheStore('test');
        $this->cacheRepository->expects('add')->andReturn(false);
        $this->mutex->useStore('test');

        $actual = $this->mutex->create($this->command);

        $this->assertFalse($actual);
    }

    public function testCanCreateMutexWithLockProvider(): void
    {
        $this->cacheFactory->expects('store')->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->andReturn(new ArrayStore);

        $actual = $this->mutex->create($this->command);

        $this->assertTrue($actual);
    }

    public function testCannotCreateMutexIfAlreadyExistWithLockProvider(): void
    {
        $store = new ArrayStore;
        $this->cacheFactory->expects('store')->twice()->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->twice()->andReturn($store);

        $this->mutex->create($this->command);
        $actual = $this->mutex->create($this->command);

        $this->assertFalse($actual);
    }

    public function testExistsProbesLockWithoutTakingOwnership(): void
    {
        $store = new ArrayStore;
        $this->cacheFactory->shouldReceive('store')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('getStore')->andReturn($store);

        $this->assertFalse($this->mutex->exists($this->command));
        $this->assertTrue($this->mutex->create($this->command));
        $this->assertTrue($this->mutex->exists($this->command));
        $this->assertFalse($this->mutex->create($this->command));
    }

    public function testCanForgetMutexWithLockProvider(): void
    {
        $store = new ArrayStore;
        $this->cacheFactory->shouldReceive('store')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('getStore')->andReturn($store);

        $this->assertTrue($this->mutex->create($this->command));
        $this->assertTrue($this->mutex->forget($this->command));
        $this->assertFalse($this->mutex->exists($this->command));
    }

    public function testCanCreateMutexWithCustomConnectionWithLockProvider(): void
    {
        $this->cacheFactory->expects('store')->with('test')->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->andReturn(new ArrayStore);
        $this->mutex->useStore('test');

        $actual = $this->mutex->create($this->command);

        $this->assertTrue($actual);
    }

    /**
     * Expect a cache store without native locks.
     */
    private function mockUsingCacheStore(?string $store = null): void
    {
        $this->cacheFactory->expects('store')->with($store)->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->andReturn(m::mock(Store::class));
    }

    public function testCommandMutexNameWithoutIsolatedMutexNameMethod(): void
    {
        $this->mockUsingCacheStore();

        $this->cacheRepository->expects('add')
            ->withArgs(function (string $key): bool {
                $this->assertSame('framework' . DIRECTORY_SEPARATOR . 'command-command-name', $key);

                return true;
            })
            ->andReturn(true);

        $this->mutex->create($this->command);
    }

    public function testCommandMutexNameWithIsolatedMutexNameMethod(): void
    {
        $command = new class extends Command {
            protected ?string $name = 'command-name';

            /**
             * Get the command's isolation identifier.
             */
            public function isolatableId(): string
            {
                return 'isolated';
            }
        };

        $this->mockUsingCacheStore();

        $this->cacheRepository->expects('add')
            ->withArgs(function (string $key): bool {
                $this->assertSame('framework' . DIRECTORY_SEPARATOR . 'command-command-name-isolated', $key);

                return true;
            })
            ->andReturn(true);

        $this->mutex->create($command);
    }
}
