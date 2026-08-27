<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Console;

use Closure;
use Error;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\Checks\HashFieldExpirationCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\PhpRedisCheck;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;
use Hypervel\Cache\Redis\Console\DoctorCommand;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TaggedCache;
use Hypervel\Cache\TagMode;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Console\OutputStyle;
use Hypervel\Contracts\Cache\Factory as CacheContract;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConnection;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class DoctorCommandTest extends TestCase
{
    private const int CLEANUP_TEST_TAG_COUNT = 25;

    private const int CLEANUP_PATTERN_COUNT = 4;

    #[DataProvider('phpRedisRequirements')]
    public function testPhpRedisCheckUsesRequirementForTagMode(string $taggingMode, string $requiredVersion): void
    {
        $installedVersion = phpversion('redis');
        $this->assertIsString($installedVersion);

        $check = new PhpRedisCheck($taggingMode);
        $result = $check->run();
        $meetsRequirement = version_compare($installedVersion, $requiredVersion, '>=');

        $this->assertSame([
            [
                'passed' => true,
                'description' => "PHPRedis extension is installed (v{$installedVersion})",
            ],
            [
                'passed' => $meetsRequirement,
                'description' => "PHPRedis version >= {$requiredVersion}",
            ],
        ], $result->assertions);

        $this->assertSame(
            $meetsRequirement
                ? null
                : "Upgrade PHPRedis: pie install phpredis/phpredis (current: {$installedVersion}, required: {$requiredVersion}+)",
            $check->getFixInstructions(),
        );
    }

    /**
     * Provide PHPRedis requirements by tag mode.
     *
     * @return array<string, array{string, string}>
     */
    public static function phpRedisRequirements(): array
    {
        return [
            'all mode' => ['all', '6.1.0'],
            'any mode' => ['any', '6.3.0'],
        ];
    }

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

    public function testCleanupReportsEveryFailureAndContinuesThroughLaterOperations(): void
    {
        $tagException = new Error('tag cleanup failed');
        $cachePatternException = new RuntimeException('cache pattern cleanup failed');
        $tagPatternException = new Error('tag pattern cleanup failed');
        $registryException = new RuntimeException('registry cleanup failed');
        $context = $this->createCleanupContext(
            tagException: $tagException,
            // Cleanup flushes two cache-value patterns, then two tag-storage patterns.
            patternExceptions: [
                1 => $cachePatternException,
                3 => $tagPatternException,
            ],
            registryException: $registryException,
        );
        $command = new ControlledDoctorCommand;
        $command->setHypervel($this->app);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $command->exposedCleanup($context);
        $command->exposedCleanup($this->createCleanupContext());

        $functionalResult = new CheckResult;
        $functionalResult->assert(false, 'controlled functional failure');
        $command->exposedDisplayCheckResult($functionalResult);
        $command->exposedDisplaySummary();

        $outputText = $output->fetch();
        $cachePattern = $context->getCacheValuePatterns('_doctor:test:')[0];
        $tagPattern = $context->getTagStoragePatterns('_doctor:test:')[0];
        $this->assertStringContainsString(
            "Cleanup: flush tag '_doctor:test:products' failed (" . $tagException::class . '): tag cleanup failed',
            $outputText,
        );
        $this->assertStringContainsString(
            "Cleanup: flush cache keys matching '{$cachePattern}' failed (" . $cachePatternException::class . '): cache pattern cleanup failed',
            $outputText,
        );
        $this->assertStringContainsString(
            "Cleanup: flush tag storage matching '{$tagPattern}' failed (" . $tagPatternException::class . '): tag pattern cleanup failed',
            $outputText,
        );
        $this->assertStringContainsString(
            'Cleanup: clean tag registry failed (' . $registryException::class . '): registry cleanup failed',
            $outputText,
        );
        $this->assertSame(1, substr_count($outputText, 'Cleanup complete.'));
        $this->assertStringContainsString('1 TEST(S) FAILED (out of 1 total)', $outputText);
        $this->assertStringContainsString('Failed tests:', $outputText);
        $this->assertStringContainsString('controlled functional failure', $outputText);
        $this->assertStringContainsString('Cleanup failures:', $outputText);
    }

    public function testPreflightCleanupFailureRemainsVisibleAndFailsTheCompletedRun(): void
    {
        $cleanupException = new Error('preflight cleanup failed');
        $context = $this->createCleanupContext(
            tagException: $cleanupException,
            cleanupInvocations: 2,
        );
        $this->bindDoctorContext($context);
        $command = new ControlledDoctorCommand;
        $command->setHypervel($this->app);
        $output = new BufferedOutput;

        $result = $command->run(new ArrayInput(['--store' => 'redis']), $output);

        $outputText = $output->fetch();
        $this->assertSame(DoctorCommand::FAILURE, $result);
        $this->assertTrue($command->functionalChecksRan);
        $this->assertStringContainsString(
            "Preflight cleanup: flush tag '_doctor:test:products' failed (" . $cleanupException::class . '): preflight cleanup failed',
            $outputText,
        );
        $this->assertStringContainsString('ALL TESTS PASSED (1 tests)', $outputText);
        $this->assertStringContainsString('Cleanup failures:', $outputText);
        $this->assertStringContainsString('Cleanup complete.', $outputText);
    }

    public function testCleanupFailureDoesNotReplaceActiveFunctionalException(): void
    {
        $functionalException = new RuntimeException('functional checks failed');
        $cleanupException = new Error('final cleanup failed');
        $context = $this->createCleanupContext(
            tagException: $cleanupException,
            failingTagFlush: self::CLEANUP_TEST_TAG_COUNT + 1,
            cleanupInvocations: 2,
        );
        $this->bindDoctorContext($context);
        $command = new ControlledDoctorCommand($functionalException);
        $command->setHypervel($this->app);
        $output = new BufferedOutput;
        $caughtException = null;

        try {
            $command->run(new ArrayInput(['--store' => 'redis']), $output);
        } catch (Throwable $exception) {
            $caughtException = $exception;
        }

        $this->assertSame($functionalException, $caughtException);
        $this->assertNull($functionalException->getPrevious());
        $this->assertStringContainsString(
            "Cleanup: flush tag '_doctor:test:products' failed (" . $cleanupException::class . '): final cleanup failed',
            $output->fetch(),
        );
    }

    public function testSystemInformationErrorIsVisibleAndDoesNotStopDiagnostics(): void
    {
        config()->set('cache.default', 'redis');
        $displayException = new Error('system information failed');
        $connection = m::mock(RedisConnection::class);
        $storeContext = m::mock(StoreContext::class);
        $storeContext->shouldReceive('withConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback): mixed => $callback($connection));

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getContext')->once()->andReturn($storeContext);
        $store->shouldReceive('getTagMode')->once()->andReturn(TagMode::Any);
        $store->shouldReceive('getPrefix')->once()->andReturn('cache:');

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')->once()->andReturn($store);

        $storeCalls = 0;
        $cacheManager = m::mock(CacheContract::class);
        $cacheManager->shouldReceive('store')
            ->twice()
            ->with('redis')
            ->andReturnUsing(function () use (&$storeCalls, $displayException, $repository): Repository {
                ++$storeCalls;

                if ($storeCalls === 1) {
                    throw $displayException;
                }

                return $repository;
            });
        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new SystemInformationDoctorCommand;
        $command->setHypervel($this->app);
        $output = new BufferedOutput;

        $result = $command->run(new ArrayInput(['--store' => 'redis']), $output);

        $this->assertSame(DoctorCommand::SUCCESS, $result);
        $this->assertTrue($command->functionalChecksRan);
        $this->assertStringContainsString(
            'Service: Connection failed (' . $displayException::class . '): system information failed',
            $output->fetch(),
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
            ->andReturnUsing(function (callable $callback): mixed {
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
            ->andReturnUsing(function (callable $callback): mixed {
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
            ->andReturnUsing(function (callable $callback) use (&$borrowedConnection, $connection): mixed {
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
            ->andReturnUsing(function (callable $callback): mixed {
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
            ->andReturnUsing(function (callable $callback): mixed {
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

    /**
     * Create a doctor context with controlled cleanup failures.
     *
     * @param array<int, Throwable> $patternExceptions
     */
    private function createCleanupContext(
        ?Throwable $tagException = null,
        int $failingTagFlush = 1,
        array $patternExceptions = [],
        ?Throwable $registryException = null,
        int $cleanupInvocations = 1,
    ): DoctorContext {
        $taggedCache = m::mock(TaggedCache::class);
        $tagFlushCalls = 0;
        $taggedCache->shouldReceive('flush')
            ->times(self::CLEANUP_TEST_TAG_COUNT * $cleanupInvocations)
            ->andReturnUsing(function () use (&$tagFlushCalls, $failingTagFlush, $tagException): bool {
                ++$tagFlushCalls;

                if ($tagException !== null && $tagFlushCalls === $failingTagFlush) {
                    throw $tagException;
                }

                return true;
            });

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('tags')
            ->times(self::CLEANUP_TEST_TAG_COUNT * $cleanupInvocations)
            ->andReturn($taggedCache);

        $connection = m::mock(RedisConnection::class);
        $patternCalls = 0;
        $connection->shouldReceive('flushByPattern')
            ->times(self::CLEANUP_PATTERN_COUNT * $cleanupInvocations)
            ->andReturnUsing(function (string $pattern) use (&$patternCalls, $patternExceptions): int {
                ++$patternCalls;

                if (isset($patternExceptions[$patternCalls])) {
                    throw $patternExceptions[$patternCalls];
                }

                return 0;
            });

        if ($registryException === null) {
            $connection->shouldReceive('zRange')->times($cleanupInvocations)->andReturn([]);
            $connection->shouldReceive('zCard')->times($cleanupInvocations)->andReturn(0);
            $connection->shouldReceive('del')->times($cleanupInvocations)->andReturn(1);
        } else {
            $connection->shouldReceive('zRange')->once()->andThrow($registryException);
            $connection->shouldNotReceive('zCard');
            $connection->shouldNotReceive('del');
        }

        $storeContext = m::mock(StoreContext::class);
        $storeContext->shouldReceive('registryKey')->andReturn('cache:_any:tag_registry');
        $storeContext->shouldReceive('withConnection')
            ->andReturnUsing(fn (callable $callback): mixed => $callback($connection));

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getContext')->andReturn($storeContext);
        $store->shouldReceive('getTagMode')->andReturn(TagMode::Any);
        $store->shouldReceive('getPrefix')->andReturn('cache:');

        return new DoctorContext(
            cache: $repository,
            store: $store,
            redis: $connection,
            cachePrefix: 'cache:',
            storeName: 'redis',
        );
    }

    /**
     * Bind a controlled doctor context to the command's cache lookup.
     */
    private function bindDoctorContext(DoctorContext $context): void
    {
        $context->cache->shouldReceive('getStore')->once()->andReturn($context->store);

        $cacheManager = m::mock(CacheContract::class);
        $cacheManager->shouldReceive('store')->once()->with('redis')->andReturn($context->cache);
        $this->app->instance(CacheContract::class, $cacheManager);
    }
}

class ControlledDoctorCommand extends DoctorCommand
{
    public bool $functionalChecksRan = false;

    /**
     * Create a doctor command with controlled functional behavior.
     */
    public function __construct(private readonly ?Throwable $functionalException = null)
    {
        parent::__construct();
    }

    /**
     * Clean up through the production cleanup path.
     */
    public function exposedCleanup(DoctorContext $context, bool $silent = false): void
    {
        $this->cleanup($context, $silent);
    }

    /**
     * Display a controlled functional result.
     */
    public function exposedDisplayCheckResult(CheckResult $result): void
    {
        $this->displayCheckResult($result);
    }

    /**
     * Display the production summary.
     */
    public function exposedDisplaySummary(): void
    {
        $this->displaySummary();
    }

    /**
     * Skip unrelated system information in cleanup-focused tests.
     */
    protected function displaySystemInformation(): void
    {
    }

    /**
     * Accept the controlled environment.
     */
    protected function runEnvironmentChecks(
        string $storeName,
        RedisStore $store,
        string $taggingMode,
        RedisConnection $redis,
    ): bool {
        return true;
    }

    /**
     * Run the controlled functional result and exception.
     */
    protected function runFunctionalChecks(DoctorContext $context): void
    {
        $this->functionalChecksRan = true;
        $result = new CheckResult;
        $result->assert(true, 'controlled functional check');
        $this->displayCheckResult($result);

        if ($this->functionalException !== null) {
            throw $this->functionalException;
        }
    }

    /**
     * Skip unrelated cleanup verification in cleanup-focused tests.
     */
    protected function runCleanupVerification(DoctorContext $context): void
    {
    }
}

class SystemInformationDoctorCommand extends DoctorCommand
{
    public bool $functionalChecksRan = false;

    /**
     * Accept the controlled environment.
     */
    protected function runEnvironmentChecks(
        string $storeName,
        RedisStore $store,
        string $taggingMode,
        RedisConnection $redis,
    ): bool {
        return true;
    }

    /**
     * Record that functional diagnostics continued.
     */
    protected function runFunctionalChecks(DoctorContext $context): void
    {
        $this->functionalChecksRan = true;
    }

    /**
     * Skip unrelated cleanup in the system-information test.
     */
    protected function cleanup(DoctorContext $context, bool $silent = false): void
    {
    }

    /**
     * Skip unrelated cleanup verification in the system-information test.
     */
    protected function runCleanupVerification(DoctorContext $context): void
    {
    }
}
