<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Console;

use Closure;
use Error;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\Checks\HashFieldExpirationCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\PhpRedisCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\RedisVersionCheck;
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
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class DoctorCommandTest extends RedisCacheTestCase
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

    #[DataProvider('serverRequirements')]
    public function testRedisVersionCheckUsesFeatureRequirement(string $taggingMode, array $info, bool $supported): void
    {
        $connection = $this->mockConnection()->shouldTransform(false);
        $connection->client()->allows('info')->with('server')->returns($info);

        $check = new RedisVersionCheck($connection, $taggingMode);

        $this->assertSame($supported, $check->run()->passed());

        if ($supported || $info === []) {
            $this->assertNull($check->getFixInstructions());
        } else {
            $this->assertStringContainsString('Upgrade to', $check->getFixInstructions());
        }
    }

    /**
     * Provide ordinary and hash-field-expiration server requirements.
     */
    public static function serverRequirements(): array
    {
        return [
            'ordinary Redis' => ['all', ['redis_version' => '7.0.0'], true],
            'old Redis for any mode' => ['any', ['redis_version' => '7.0.0'], false],
            'supported Redis' => ['any', ['redis_version' => '8.0.0'], true],
            'Valkey precedence' => ['any', ['valkey_version' => '9.0.0', 'redis_version' => '7.2.0'], true],
            'old Valkey' => ['any', ['valkey_version' => '8.0.0', 'redis_version' => '8.0.0'], false],
            'unknown server' => ['any', [], false],
        ];
    }

    public function testHashFieldExpirationCheckSkipsAllMode(): void
    {
        $connection = $this->mockConnection()->shouldTransform(false);
        $connection->client()->expects('hsetex')->never();
        $connection->client()->expects('hexpire')->never();
        $connection->client()->expects('del')->never();

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
        $connection = $this->mockConnection()->shouldTransform(false);
        $connection->client()->expects('hsetex')
            ->with(Argument::matches('/^erc:doctor:hash-field-expiration-test:/'), ['field' => '1'], ['EX' => 60])
            ->returns(1);
        $connection->client()->expects('hexpire')
            ->with(Argument::matches('/^erc:doctor:hash-field-expiration-test:/'), 60, ['field'])
            ->returns([1]);
        $connection->client()->expects('del')
            ->with(Argument::matches('/^erc:doctor:hash-field-expiration-test:/'))
            ->returns(1);

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
        $connection = $this->mockConnection()->shouldTransform(false);
        $connection->client()->expects('hsetex')
            ->with(Argument::matches('/^erc:doctor:hash-field-expiration-test:/'), ['field' => '1'], ['EX' => 60])
            ->throws(new RuntimeException('unsupported command'));
        $connection->client()->expects('hexpire')->never();
        $connection->client()->expects('del')
            ->with(Argument::matches('/^erc:doctor:hash-field-expiration-test:/'))
            ->returns(0);

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
            'Any tagging mode requires Redis 8.0.0+ or Valkey 9.0.0+ for hash-field expiration commands such as HSETEX and HEXPIRE. Upgrade your Redis/Valkey server, or switch to all tagging mode.',
            $check->getFixInstructions()
        );
    }

    public function testHashFieldExpirationCheckReportsFailureWhenProbeReturnsFalse(): void
    {
        $connection = $this->mockConnection()->shouldTransform(false);
        $connection->client()->expects('hsetex')
            ->with(Argument::matches('/^erc:doctor:hash-field-expiration-test:/'), ['field' => '1'], ['EX' => 60])
            ->returns(false);
        $connection->client()->expects('hexpire')
            ->with(Argument::matches('/^erc:doctor:hash-field-expiration-test:/'), 60, ['field'])
            ->returns(false);
        $connection->client()->expects('del')
            ->with(Argument::matches('/^erc:doctor:hash-field-expiration-test:/'))
            ->returns(0);

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
            'Any tagging mode requires Redis 8.0.0+ or Valkey 9.0.0+ for hash-field expiration commands such as HSETEX and HEXPIRE. Upgrade your Redis/Valkey server, or switch to all tagging mode.',
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
        $connection = $this->mockConnection()->shouldTransform(false);
        $storeContext = Double::for(StoreContext::class)->strict();
        $storeContext->expects('withConnection')
            ->resolves(fn (callable $callback): mixed => $callback($connection));

        $store = Double::for(RedisStore::class)->strict();
        $store->expects('getContext')->returns($storeContext);
        $store->expects('getTagMode')->returns(TagMode::Any);
        $store->expects('getPrefix')->returns('cache:');

        $repository = Double::for(Repository::class)->strict();
        $repository->expects('getStore')->returns($store);

        $storeCalls = 0;
        $cacheManager = Double::for(CacheContract::class)->strict();
        $cacheManager->expects('store')
            ->times(2)
            ->with('redis')
            ->resolves(function () use (&$storeCalls, $displayException, $repository): Repository {
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
        $nonRedisStore = Double::for(Store::class)->strict();

        $repository = Double::for(Repository::class)->strict();
        $repository->allows('getStore')
            ->returns($nonRedisStore);

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->allows('store')
            ->with('file')
            ->returns($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new DoctorCommand;
        $command->setHypervel($this->app);
        $result = $command->run(new ArrayInput(['--store' => 'file']), new NullOutput);

        $this->assertSame(1, $result);
    }

    public function testDoctorDetectsRedisStoreFromConfig(): void
    {
        // Set up config with a redis store
        $config = Double::for(ConfigRepository::class)->strict();
        $config->allows('array')
            ->with('cache.stores')
            ->returns([
                'file' => ['driver' => 'file'],
                'redis' => ['driver' => 'redis', 'connection' => 'default'],
            ]);
        $config->allows('string')
            ->with('cache.default')
            ->returns('file');
        $config->allows('get')
            ->with('cache.stores.redis.connection', 'default')
            ->returns('default');

        $this->app->instance('config', $config);

        // Mock Redis store
        $context = Double::for(StoreContext::class)->strict();
        $context->allows('withConnection')
            ->resolves(function (callable $callback): mixed {
                $connection = $this->mockConnection()->shouldTransform(false);
                $connection->client()->allows('info')->with('server')->returns(['redis_version' => '7.0.0']);
                return $callback($connection);
            });

        $store = Double::for(RedisStore::class)->strict();
        $store->allows('getTagMode')->returns(TagMode::Any);
        $store->allows('getContext')->returns($context);
        $store->allows('getPrefix')->returns('cache:');

        $repository = Double::for(Repository::class)->strict();
        $repository->allows('getStore')->returns($store);

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->allows('store')
            ->with('redis')
            ->returns($repository);

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

        $config = Double::for(ConfigRepository::class)->strict();
        $config->allows('string')
            ->with('cache.default')
            ->returns('file');
        $config->allows('get')
            ->with('cache.stores.0.connection', 'default')
            ->returns('custom');

        $this->app->instance('config', $config);

        // Mock Redis store
        $context = Double::for(StoreContext::class)->strict();
        $context->allows('withConnection')
            ->resolves(function (callable $callback): mixed {
                $connection = $this->mockConnection()->shouldTransform(false);
                $connection->client()->allows('info')->with('server')->returns(['redis_version' => '7.0.0']);
                return $callback($connection);
            });

        $store = Double::for(RedisStore::class)->strict();
        $store->allows('getTagMode')->returns(TagMode::All);
        $store->allows('getContext')->returns($context);
        $store->allows('getPrefix')->returns('cache:');

        $repository = Double::for(Repository::class)->strict();
        $repository->allows('getStore')->returns($store);

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->allows('store')
            ->with('0')
            ->returns($repository);

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
        $config = Double::for(ConfigRepository::class)->strict();
        $config->allows('string')
            ->with('cache.default')
            ->returns('redis');

        $this->app->instance('config', $config);

        $borrowedConnection = false;
        $assertConnectionBorrowed = function () use (&$borrowedConnection): void {
            $this->assertTrue($borrowedConnection);
        };

        $connection = $this->mockConnection()->shouldTransform(false);
        $connection->client()->expects('info')
            ->with('server')
            ->returns(['redis_version' => '7.0.0']);

        $context = Double::for(StoreContext::class)->strict();
        $context->expects('withConnection')
            ->times(2)
            ->resolves(function (callable $callback) use (&$borrowedConnection, $connection): mixed {
                $borrowedConnection = true;

                try {
                    return $callback($connection);
                } finally {
                    $borrowedConnection = false;
                }
            });

        $store = Double::for(RedisStore::class)->strict();
        $store->allows('getTagMode')->returns(TagMode::Any);
        $store->allows('getContext')->returns($context);
        $store->allows('getPrefix')->returns('cache:');

        $repository = Double::for(Repository::class)->strict();
        $repository->allows('getStore')->returns($store);

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->allows('store')
            ->with('redis')
            ->returns($repository);

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
        $config = Double::for(ConfigRepository::class)->strict();
        $config->allows('string')
            ->with('cache.default')
            ->returns('redis');
        $config->allows('get')
            ->with('cache.stores.redis.connection', 'default')
            ->returns('default');

        $this->app->instance('config', $config);

        // Mock Redis store with 'all' mode
        $context = Double::for(StoreContext::class)->strict();
        $context->allows('withConnection')
            ->resolves(function (callable $callback): mixed {
                $connection = $this->mockConnection()->shouldTransform(false);
                $connection->client()->allows('info')->with('server')->returns(['redis_version' => '7.0.0']);
                return $callback($connection);
            });

        $store = Double::for(RedisStore::class)->strict();
        $store->allows('getTagMode')->returns(TagMode::All);
        $store->allows('getContext')->returns($context);
        $store->allows('getPrefix')->returns('cache:');

        $repository = Double::for(Repository::class)->strict();
        $repository->allows('getStore')->returns($store);

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->allows('store')
            ->with('redis')
            ->returns($repository);

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
        $config = Double::for(ConfigRepository::class)->strict();
        $config->allows('array')
            ->with('cache.stores')
            ->returns([
                'file' => ['driver' => 'file'],
                'array' => ['driver' => 'array'],
            ]);
        $config->allows('string')
            ->with('cache.default')
            ->returns('file');

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
        $config = Double::for(ConfigRepository::class)->strict();
        $config->allows('array')
            ->with('cache.stores')
            ->returns([
                'redis' => ['driver' => 'redis', 'connection' => 'default'],
            ]);
        $config->allows('string')
            ->with('cache.default')
            ->returns('redis');
        $config->allows('get')
            ->with('cache.stores.redis.connection', 'default')
            ->returns('default');

        $this->app->instance('config', $config);

        $context = Double::for(StoreContext::class)->strict();
        $context->allows('withConnection')
            ->resolves(function (callable $callback): mixed {
                $connection = $this->mockConnection()->shouldTransform(false);
                $connection->client()->allows('info')->with('server')->returns(['redis_version' => '7.2.4']);
                return $callback($connection);
            });

        $store = Double::for(RedisStore::class)->strict();
        $store->allows('getTagMode')->returns(TagMode::Any);
        $store->allows('getContext')->returns($context);
        $store->allows('getPrefix')->returns('cache:');

        $repository = Double::for(Repository::class)->strict();
        $repository->allows('getStore')->returns($store);

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->allows('store')
            ->with('redis')
            ->returns($repository);

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
        $taggedCache = Double::for(TaggedCache::class)->strict();
        $tagFlushCalls = 0;
        $taggedCache->expects('flush')
            ->times(self::CLEANUP_TEST_TAG_COUNT * $cleanupInvocations)
            ->resolves(function () use (&$tagFlushCalls, $failingTagFlush, $tagException): bool {
                ++$tagFlushCalls;

                if ($tagException !== null && $tagFlushCalls === $failingTagFlush) {
                    throw $tagException;
                }

                return true;
            });

        $repository = Double::for(Repository::class)->strict();
        $repository->expects('tags')
            ->times(self::CLEANUP_TEST_TAG_COUNT * $cleanupInvocations)
            ->returns($taggedCache);

        $connection = $this->mockConnection()->shouldTransform(false);
        $patternCalls = 0;
        $connection->expects('flushByPattern')
            ->times(self::CLEANUP_PATTERN_COUNT * $cleanupInvocations)
            ->resolves(function (string $pattern) use (&$patternCalls, $patternExceptions): int {
                ++$patternCalls;

                if (isset($patternExceptions[$patternCalls])) {
                    throw $patternExceptions[$patternCalls];
                }

                return 0;
            });

        if ($registryException === null) {
            $connection->client()->expects('zRange')->times($cleanupInvocations)->returns([]);
            $connection->client()->expects('zCard')->times($cleanupInvocations)->returns(0);
            $connection->client()->expects('del')->times($cleanupInvocations)->returns(1);
        } else {
            $connection->client()->expects('zRange')->throws($registryException);
            $connection->client()->expects('zCard')->never();
            $connection->client()->expects('del')->never();
        }

        $storeContext = Double::for(StoreContext::class)->strict();
        $storeContext->allows('registryKey')->returns('cache:_any:tag_registry');
        $storeContext->allows('withConnection')
            ->resolves(fn (callable $callback): mixed => $callback($connection));

        $store = Double::for(RedisStore::class)->strict();
        $store->allows('getContext')->returns($storeContext);
        $store->allows('getTagMode')->returns(TagMode::Any);
        $store->allows('getPrefix')->returns('cache:');

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
        $context->cache->expects('getStore')->returns($context->store);

        $cacheManager = Double::for(CacheContract::class)->strict();
        $cacheManager->expects('store')->with('redis')->returns($context->cache);
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
