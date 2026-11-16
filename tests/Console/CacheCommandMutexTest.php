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
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class CacheCommandMutexTest extends TestCase
{
    use VerifiesDoubles;

    protected CacheCommandMutex $mutex;

    protected Command $command;

    protected Factory&DoubleInterface $cacheFactory;

    protected Repository&DoubleInterface $cacheRepository;

    /**
     * Set up the command mutex and its dependencies.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFactory = Double::for(Factory::class);
        $this->cacheRepository = Double::for(Repository::class);
        $this->mutex = new CacheCommandMutex($this->cacheFactory);
        $this->command = new class extends Command {
            protected ?string $name = 'command-name';
        };
    }

    public function testCanCreateMutex(): void
    {
        $this->mockUsingCacheStore();
        $this->cacheRepository->expects('add')->returns(true);
        $actual = $this->mutex->create($this->command);

        $this->assertTrue($actual);
    }

    public function testCannotCreateMutexIfAlreadyExist(): void
    {
        $this->mockUsingCacheStore();
        $this->cacheRepository->expects('add')->returns(false);
        $actual = $this->mutex->create($this->command);

        $this->assertFalse($actual);
    }

    public function testCanCreateMutexWithCustomConnection(): void
    {
        $this->mockUsingCacheStore('test');
        $this->cacheRepository->expects('add')->returns(false);
        $this->mutex->useStore('test');

        $actual = $this->mutex->create($this->command);

        $this->assertFalse($actual);
    }

    public function testCanCreateMutexWithLockProvider(): void
    {
        $this->cacheFactory->expects('store')->with(null)->returns($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->returns(new ArrayStore);

        $actual = $this->mutex->create($this->command);

        $this->assertTrue($actual);
    }

    public function testCannotCreateMutexIfAlreadyExistWithLockProvider(): void
    {
        $store = new ArrayStore;
        $this->cacheFactory->expects('store')->times(2)->with(null)->returns($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->times(2)->returns($store);

        $this->mutex->create($this->command);
        $actual = $this->mutex->create($this->command);

        $this->assertFalse($actual);
    }

    public function testExistsProbesLockWithoutTakingOwnership(): void
    {
        $store = new ArrayStore;
        $this->cacheFactory->allows('store')->returns($this->cacheRepository);
        $this->cacheRepository->allows('getStore')->returns($store);

        $this->assertFalse($this->mutex->exists($this->command));
        $this->assertTrue($this->mutex->create($this->command));
        $this->assertTrue($this->mutex->exists($this->command));
        $this->assertFalse($this->mutex->create($this->command));
    }

    public function testCanForgetMutexWithLockProvider(): void
    {
        $store = new ArrayStore;
        $this->cacheFactory->allows('store')->returns($this->cacheRepository);
        $this->cacheRepository->allows('getStore')->returns($store);

        $this->assertTrue($this->mutex->create($this->command));
        $this->assertTrue($this->mutex->forget($this->command));
        $this->assertFalse($this->mutex->exists($this->command));
    }

    public function testCanCreateMutexWithCustomConnectionWithLockProvider(): void
    {
        $this->cacheFactory->expects('store')->with('test')->returns($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->returns(new ArrayStore);
        $this->mutex->useStore('test');

        $actual = $this->mutex->create($this->command);

        $this->assertTrue($actual);
    }

    /**
     * Expect a cache store without native locks.
     */
    private function mockUsingCacheStore(?string $store = null): void
    {
        $this->cacheFactory->expects('store')->with($store)->returns($this->cacheRepository);
        $this->cacheRepository->expects('getStore')->returns(Double::for(Store::class));
    }

    public function testCommandMutexNameWithoutIsolatedMutexNameMethod(): void
    {
        $this->mockUsingCacheStore();

        $this->cacheRepository->expects('add')
            ->with('framework' . DIRECTORY_SEPARATOR . 'command-command-name', Argument::remaining())
            ->returns(true);

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
            ->with('framework' . DIRECTORY_SEPARATOR . 'command-command-name-isolated', Argument::remaining())
            ->returns(true);

        $this->mutex->create($command);
    }
}
