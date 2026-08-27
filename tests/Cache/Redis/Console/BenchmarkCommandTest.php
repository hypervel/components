<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Console;

use Error;
use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Console\BenchmarkCommand;
use Hypervel\Cache\Redis\Exceptions\BenchmarkMemoryException;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TagMode;
use Hypervel\Console\Command;
use Hypervel\Console\OutputStyle;
use Hypervel\Contracts\Cache\Factory as CacheContract;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

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

    public function testMemoryRecoveryGuidanceInheritsTheSharedPrefixWhenStorePrefixIsOmitted(): void
    {
        config()->set('cache.prefix', 'shared:');

        $command = $this->createCommand();
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $command->exposedDisplayMemoryError(new BenchmarkMemoryException(1, 2, 50), 'redis');

        $this->assertStringContainsString('redis-cli KEYS "shared:', $output->fetch());
    }

    public function testSuccessfulBenchmarkCleansUpOnce(): void
    {
        $this->mockFullCacheStore('redis');
        $context = $this->mockBenchmarkContext();
        $command = $this->createFullCommand($context);
        $output = new BufferedOutput;

        $this->assertSame(Command::SUCCESS, $command->run($this->benchmarkInput(), $output));
        $this->assertTrue($command->comparisonRan);
    }

    public function testSuccessfulBenchmarkFailsWhenCleanupThrows(): void
    {
        $cleanupException = new RuntimeException('cleanup failed');
        $this->mockFullCacheStore('redis');
        $context = $this->mockBenchmarkContext($cleanupException);
        $command = $this->createFullCommand($context);
        $output = new BufferedOutput;

        $this->assertSame(Command::FAILURE, $command->run($this->benchmarkInput(), $output));
        $this->assertTrue($command->comparisonRan);
        $this->assertStringContainsString(
            'Benchmark cleanup failed (' . $cleanupException::class . '): cleanup failed',
            $output->fetch(),
        );
    }

    public function testMemoryFailureStillCleansUp(): void
    {
        $this->mockFullCacheStore('redis');
        $context = $this->mockBenchmarkContext();
        $command = $this->createFullCommand(benchmarkContext: $context, comparisonException: new BenchmarkMemoryException(1, 2, 50));
        $output = new BufferedOutput;

        $this->assertSame(Command::FAILURE, $command->run($this->benchmarkInput(), $output));
        $outputText = $output->fetch();
        $this->assertStringContainsString('Benchmark aborted due to memory constraints.', $outputText);
        $this->assertStringContainsString('Automatic cleanup will run next.', $outputText);
        $this->assertStringNotContainsString('Cleanup skipped', $outputText);
    }

    public function testMemoryAndCleanupFailuresAreBothReported(): void
    {
        $cleanupException = new RuntimeException('cleanup after memory failure failed');
        $this->mockFullCacheStore('redis');
        $context = $this->mockBenchmarkContext($cleanupException);
        $command = $this->createFullCommand(benchmarkContext: $context, comparisonException: new BenchmarkMemoryException(1, 2, 50));
        $output = new BufferedOutput;

        $this->assertSame(Command::FAILURE, $command->run($this->benchmarkInput(), $output));
        $outputText = $output->fetch();
        $this->assertStringContainsString('Benchmark aborted due to memory constraints.', $outputText);
        $this->assertStringContainsString(
            'Benchmark cleanup failed (' . $cleanupException::class . '): cleanup after memory failure failed',
            $outputText,
        );
    }

    public function testCleanupFailureDoesNotReplaceUnexpectedSuiteException(): void
    {
        $suiteException = new RuntimeException('suite failed');
        $cleanupException = new RuntimeException('cleanup after suite failure failed');
        $this->mockFullCacheStore('redis');
        $context = $this->mockBenchmarkContext($cleanupException);
        $command = $this->createFullCommand($context, $suiteException);
        $output = new BufferedOutput;
        $caughtException = null;

        try {
            $command->run($this->benchmarkInput(), $output);
        } catch (Throwable $exception) {
            $caughtException = $exception;
        }

        $this->assertSame($suiteException, $caughtException);
        $this->assertNull($suiteException->getPrevious());
        $this->assertStringContainsString(
            'Benchmark cleanup failed (' . $cleanupException::class . '): cleanup after suite failure failed',
            $output->fetch(),
        );
    }

    public function testSystemInformationFailureIsReportedWithoutStoppingBenchmark(): void
    {
        $displayException = new Error('service info failed');
        $this->mockFullCacheStore('redis', $displayException);
        $context = $this->mockBenchmarkContext();
        $command = $this->createFullCommand($context);
        $output = new BufferedOutput;

        $this->assertSame(Command::SUCCESS, $command->run($this->benchmarkInput(), $output));
        $this->assertTrue($command->comparisonRan);
        $this->assertStringContainsString(
            'Cache Service: Connection failed (' . $displayException::class . '): service info failed',
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

    /**
     * Mock the cache calls made by the complete benchmark command.
     */
    private function mockFullCacheStore(string $name, ?Throwable $displayException = null): void
    {
        $store = m::mock(RedisStore::class);

        if ($displayException === null) {
            $storeContext = m::mock(StoreContext::class);
            $storeContext->shouldReceive('withConnection')
                ->once()
                ->andReturn(['redis_version' => '8.0.0']);

            $store->shouldReceive('getContext')->once()->andReturn($storeContext);
            $store->shouldReceive('getTagMode')->once()->andReturn(TagMode::Any);
        } else {
            $store->shouldNotReceive('getContext');
            $store->shouldNotReceive('getTagMode');
        }

        $repository = m::mock(Repository::class);

        if ($displayException === null) {
            $repository->shouldReceive('getStore')->twice()->andReturn($store);
        } else {
            $getStoreCalls = 0;
            $repository->shouldReceive('getStore')
                ->twice()
                ->andReturnUsing(function () use (&$getStoreCalls, $displayException, $store): RedisStore {
                    ++$getStoreCalls;

                    if ($getStoreCalls === 2) {
                        throw $displayException;
                    }

                    return $store;
                });
        }

        $repository->shouldReceive('get')->once()->with('test')->andReturnNull();

        $cacheManager = m::mock(CacheContract::class);
        $cacheManager->shouldReceive('store')->times(3)->with($name)->andReturn($repository);

        $this->app->instance(CacheContract::class, $cacheManager);
    }

    /**
     * Mock a benchmark context with controlled cleanup behavior.
     */
    private function mockBenchmarkContext(?Throwable $cleanupException = null): BenchmarkContext
    {
        $context = m::mock(BenchmarkContext::class);
        $cleanup = $context->shouldReceive('cleanup')->once();

        if ($cleanupException === null) {
            $cleanup->andReturnNull();
        } else {
            $cleanup->andThrow($cleanupException);
        }

        return $context;
    }

    /**
     * Create the input for a complete benchmark run.
     */
    private function benchmarkInput(): ArrayInput
    {
        return new ArrayInput([
            '--scale' => 'small',
            '--runs' => '1',
            '--compare-tag-modes' => true,
            '--force' => true,
            '--store' => 'redis',
        ]);
    }

    private function createCommand(): TestableBenchmarkCommand
    {
        $command = new TestableBenchmarkCommand;
        $command->setHypervel($this->app);

        return $command;
    }

    /**
     * Create a command that retains the complete production handle flow.
     */
    private function createFullCommand(BenchmarkContext $benchmarkContext, ?Throwable $comparisonException = null): FullBenchmarkCommand
    {
        $command = new FullBenchmarkCommand($benchmarkContext, $comparisonException);
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

    public function exposedDisplayMemoryError(BenchmarkMemoryException $exception, string $storeName): void
    {
        $this->storeName = $storeName;

        parent::displayMemoryError($exception);
    }
}

class FullBenchmarkCommand extends BenchmarkCommand
{
    public bool $comparisonRan = false;

    /**
     * Create a benchmark command with controlled execution behavior.
     */
    public function __construct(
        private readonly BenchmarkContext $benchmarkContext,
        private readonly ?Throwable $comparisonException,
    ) {
        parent::__construct();
    }

    /**
     * Return the controlled benchmark context.
     */
    protected function createContext(array $config, CacheContract $cacheManager): BenchmarkContext
    {
        return $this->benchmarkContext;
    }

    /**
     * Run the controlled comparison behavior.
     */
    protected function runComparison(BenchmarkContext $context, int $runs): void
    {
        $this->comparisonRan = true;

        if ($this->comparisonException !== null) {
            throw $this->comparisonException;
        }
    }
}
