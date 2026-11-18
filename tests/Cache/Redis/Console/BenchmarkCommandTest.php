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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class BenchmarkCommandTest extends TestCase
{
    use VerifiesDoubles;

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

        $cache = Double::for(CacheContract::class)->strict();
        $cache->expects('store')->never();
        $this->app->instance(CacheContract::class, $cache);

        $command = $this->createCommand();
        $output = new BufferedOutput;

        $this->assertSame(Command::FAILURE, $command->run(new ArrayInput([]), $output));
        $this->assertStringContainsString(
            'Could not detect a cache store using the "redis" driver.',
            $output->fetch(),
        );
    }

    public function testMemoryRecoveryGuidanceIncludesEveryCleanupPattern(): void
    {
        $patterns = [
            'value-pattern:*',
            'namespaced-value-pattern:*',
            'any-tag-pattern:*',
            'all-tag-pattern:*',
        ];

        $context = Double::for(BenchmarkContext::class)->strict();
        $context->expects('getCleanupPatterns')->returns($patterns);

        $command = $this->createCommand();
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $command->exposedDisplayMemoryError(new BenchmarkMemoryException(1, 2, 50), 'redis', $context);

        $outputText = $output->fetch();

        foreach ($patterns as $pattern) {
            $this->assertSame(
                1,
                substr_count($outputText, escapeshellarg($pattern)),
                "Recovery guidance should contain pattern [{$pattern}] exactly once.",
            );
        }

        $this->assertStringContainsString(
            'Flush the entire Redis database for this connection (removes all keys, not only cache)',
            $outputText,
        );
        $this->assertSame(4, substr_count($outputText, 'redis-cli --scan --pattern'));
        $this->assertSame(4, substr_count($outputText, '| xargs -r -n 1000 redis-cli UNLINK'));
        $this->assertStringNotContainsString('redis-cli KEYS', $outputText);
        $this->assertStringNotContainsString('redis-cli DEL', $outputText);
    }

    public function testCleanupPatternsInheritTheSharedPrefixWhenStorePrefixIsOmitted(): void
    {
        config()->set('cache.prefix', 'shared:');

        $context = new BenchmarkContext(
            storeName: 'redis',
            items: 1,
            tagsPerItem: 1,
            heavyTags: 1,
            command: $this->createCommand(),
            cacheManager: $this->app->make(CacheContract::class),
        );

        $this->assertSame([
            'shared:_bench:*',
            'shared:*:_bench:*',
            'shared:_any:tag:_bench:*',
            'shared:_all:tag:_bench:*',
        ], $context->getCleanupPatterns());
    }

    public function testInvalidTagModeFailsBeforeBenchmarkSetup(): void
    {
        $command = new BenchmarkCommand;
        $command->setHypervel($this->app);
        $output = new BufferedOutput;

        $this->assertSame(Command::FAILURE, $command->run(
            new ArrayInput(['--tag-mode' => 'invalid']),
            $output,
        ));
        $this->assertStringContainsString(
            'Invalid tag mode: invalid. Available: any, all',
            $output->fetch(),
        );
    }

    public function testValidTagModeIsPassedThroughAsAnEnum(): void
    {
        $this->mockFullCacheStore('redis');
        $context = $this->mockBenchmarkContext();
        $context->expects('getStoreInstance')->returns(Double::for(RedisStore::class)->strict());
        $command = $this->createFullCommand($context);

        $this->assertSame(Command::SUCCESS, $command->run(new ArrayInput([
            '--scale' => 'small',
            '--runs' => '1',
            '--tag-mode' => 'any',
            '--force' => true,
            '--store' => 'redis',
        ]), new NullOutput));
        $this->assertSame(TagMode::Any, $command->tagMode);
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

    /**
     * Configure a Redis cache store for command setup.
     */
    private function mockCacheStore(string $name): void
    {
        $store = Double::for(RedisStore::class)->strict();

        $repository = Double::for(Repository::class)->strict();
        $repository->expects('getStore')->returns($store);
        $repository->expects('get')->with('test')->returns(null);

        $cache = Double::for(CacheContract::class)->strict();
        $cache->expects('store')->times(2)->with($name)->returns($repository);

        $this->app->instance(CacheContract::class, $cache);
    }

    /**
     * Mock the cache calls made by the complete benchmark command.
     */
    private function mockFullCacheStore(string $name, ?Throwable $displayException = null): void
    {
        $store = Double::for(RedisStore::class)->strict();

        if ($displayException === null) {
            $storeContext = Double::for(StoreContext::class)->strict();
            $storeContext->expects('withConnection')
                ->returns(['redis_version' => '8.0.0']);

            $store->expects('getContext')->returns($storeContext);
            $store->expects('getTagMode')->returns(TagMode::Any);
        } else {
            $store->expects('getContext')->never();
            $store->expects('getTagMode')->never();
        }

        $repository = Double::for(Repository::class)->strict();

        if ($displayException === null) {
            $repository->expects('getStore')->times(2)->returns($store);
        } else {
            $getStoreCalls = 0;
            $repository->expects('getStore')
                ->times(2)
                ->resolves(function () use (&$getStoreCalls, $displayException, $store): RedisStore {
                    ++$getStoreCalls;

                    if ($getStoreCalls === 2) {
                        throw $displayException;
                    }

                    return $store;
                });
        }

        $repository->expects('get')->with('test')->returns(null);

        $cacheManager = Double::for(CacheContract::class)->strict();
        $cacheManager->expects('store')->times(3)->with($name)->returns($repository);

        $this->app->instance(CacheContract::class, $cacheManager);
    }

    /**
     * Mock a benchmark context with controlled cleanup behavior.
     */
    private function mockBenchmarkContext(?Throwable $cleanupException = null): BenchmarkContext
    {
        $context = Double::for(BenchmarkContext::class)->strict();
        $context->allows('getCleanupPatterns')->returns(['cache:_bench:*']);
        $cleanup = $context->expects('cleanup');

        if ($cleanupException !== null) {
            $cleanup->throws($cleanupException);
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

    /**
     * Create a command limited to setup.
     */
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

    public function exposedDisplayMemoryError(
        BenchmarkMemoryException $exception,
        string $storeName,
        BenchmarkContext $context,
    ): void {
        $this->storeName = $storeName;

        parent::displayMemoryError($exception, $context);
    }
}

class FullBenchmarkCommand extends BenchmarkCommand
{
    public bool $comparisonRan = false;

    public ?TagMode $tagMode = null;

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

    /**
     * Capture the parsed tag mode without running benchmark scenarios.
     */
    protected function runSuiteWithRuns(TagMode $tagMode, BenchmarkContext $context, int $runs, bool $returnResults = false): array
    {
        $this->tagMode = $tagMode;

        return [];
    }
}
