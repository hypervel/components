<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console;

use Exception;
use Hyperf\Command\Command;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Cache\Contracts\Factory as CacheContract;
use Hypervel\Cache\Redis\Console\Concerns\DetectsRedisStore;
use Hypervel\Cache\Redis\Console\Concerns\PerformsKeyspaceOperations;
use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\Checks\AddOperationsCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\BasicOperationsCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\BulkOperationsCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\CacheStoreCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\CheckInterface;
use Hypervel\Cache\Redis\Console\Doctor\Checks\CleanupVerificationCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\ConcurrencyCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\EdgeCasesCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\EnvironmentCheckInterface;
use Hypervel\Cache\Redis\Console\Doctor\Checks\ExpirationCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\FlushBehaviorCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\ForeverStorageCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\HashStructuresCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\HexpireCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\IncrementDecrementCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\LargeDatasetCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\MemoryLeakPreventionCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\MultipleTagsCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\PhpRedisCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\RedisVersionCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\SequentialOperationsCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\SharedTagFlushCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\TaggedOperationsCheck;
use Hypervel\Cache\Redis\Console\Doctor\Checks\TaggedRememberCheck;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;
use Hypervel\Cache\RedisStore;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Traits\HasLaravelStyleCommand;
use Symfony\Component\Console\Input\InputOption;

class DoctorCommand extends Command
{
    use DetectsRedisStore;
    use HasLaravelStyleCommand;
    use PerformsKeyspaceOperations;

    /**
     * The console command name.
     */
    protected ?string $name = 'cache:redis-doctor';

    /**
     * The console command description.
     */
    protected string $description = 'Run comprehensive system checks and tests for the Redis cache';

    private int $testsPassed = 0;

    private int $testsFailed = 0;

    /** @var list<string> */
    private array $failures = [];

    /**
     * Unique prefix to prevent collision with production data.
     * Mode-agnostic - just identifies doctor test data.
     */
    private const TEST_PREFIX = '_doctor:test:';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displayHeader();
        $this->displaySystemInformation();

        // Detect or validate store
        $storeName = $this->option('store') ?: $this->detectRedisStore();

        if (! $storeName) {
            $this->error('Could not detect a cache store using the "redis" driver.');
            $this->info('Please configure a store in config/cache.php or provide one via --store.');

            return self::FAILURE;
        }

        // Validate that the store is using redis driver
        $repository = $this->app->get(CacheContract::class)->store($storeName);
        $store = $repository->getStore();

        if (! $store instanceof RedisStore) {
            $this->error("The cache store '{$storeName}' is not using the 'redis' driver.");
            $this->error('Please update the store driver to "redis" in config/cache.php.');

            return self::FAILURE;
        }

        $tagMode = $store->getTagMode()->value;

        // Run environment checks (fail fast if requirements not met)
        $this->info('Checking System Requirements...');
        $this->newLine();

        if (! $this->runEnvironmentChecks($storeName, $store, $tagMode)) {
            return self::FAILURE;
        }

        $this->info('✓ All requirements met!');
        $this->newLine(2);

        $this->info("Testing cache store: <fg=cyan>{$storeName}</> ({$tagMode} mode)");
        $this->newLine();

        // Create context for functional checks
        $config = $this->app->get(ConfigInterface::class);
        $connectionName = $config->get("cache.stores.{$storeName}.connection", 'default');

        // Get the Redis connection from the store's context
        $context = $store->getContext();
        $redis = $context->withConnection(fn (RedisConnection $conn) => $conn);

        $doctorContext = new DoctorContext(
            cache: $repository,
            store: $store,
            redis: $redis,
            cachePrefix: $store->getPrefix(),
            storeName: $storeName,
        );

        // Run functional checks with cleanup
        try {
            $this->cleanup($doctorContext, silent: true);
            $this->runFunctionalChecks($doctorContext);
        } finally {
            $this->cleanup($doctorContext);
        }

        // Run cleanup verification after cleanup
        $this->runCleanupVerification($doctorContext);

        $this->displaySummary();

        return $this->testsFailed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Get environment check classes.
     *
     * @return list<EnvironmentCheckInterface>
     */
    protected function getEnvironmentChecks(string $storeName, RedisStore $store, string $tagMode): array
    {
        // Get connection for version checks
        $context = $store->getContext();
        $redis = $context->withConnection(fn (RedisConnection $conn) => $conn);

        return [
            new PhpRedisCheck($tagMode),
            new RedisVersionCheck($redis, $tagMode),
            new HexpireCheck($redis, $tagMode),
            new CacheStoreCheck($storeName, 'redis', $tagMode),
        ];
    }

    /**
     * Get functional check classes.
     *
     * @return list<CheckInterface>
     */
    protected function getFunctionalChecks(): array
    {
        return [
            new BasicOperationsCheck(),
            new TaggedOperationsCheck(),
            new TaggedRememberCheck(),
            new MultipleTagsCheck(),
            new SharedTagFlushCheck(),
            new IncrementDecrementCheck(),
            new AddOperationsCheck(),
            new ForeverStorageCheck(),
            new BulkOperationsCheck(),
            new FlushBehaviorCheck(),
            new EdgeCasesCheck(),
            new HashStructuresCheck(),
            new ExpirationCheck(),
            new MemoryLeakPreventionCheck(),
            new LargeDatasetCheck(),
            new SequentialOperationsCheck(),
            new ConcurrencyCheck(),
        ];
    }

    /**
     * Run environment checks. Returns false if any check fails.
     */
    protected function runEnvironmentChecks(string $storeName, RedisStore $store, string $taggingMode): bool
    {
        $allPassed = true;

        foreach ($this->getEnvironmentChecks($storeName, $store, $taggingMode) as $check) {
            $result = $check->run();

            foreach ($result->assertions as $assertion) {
                if ($assertion['passed']) {
                    $this->line("  <fg=green>✓</> {$assertion['description']}");
                } else {
                    $this->line("  <fg=red>✗</> {$assertion['description']}");
                    $allPassed = false;
                }
            }

            // If this check failed, show fix instructions and stop
            if (! $result->passed()) {
                $this->newLine();
                $fixInstructions = $check->getFixInstructions();

                if ($fixInstructions) {
                    $this->error('Fix: ' . $fixInstructions);
                }

                return false;
            }
        }

        return $allPassed;
    }

    /**
     * Run all functional checks.
     */
    protected function runFunctionalChecks(DoctorContext $context): void
    {
        $this->info('Running Integration Tests...');
        $this->newLine();

        foreach ($this->getFunctionalChecks() as $check) {
            // Inject output for checks that need it
            if (method_exists($check, 'setOutput')) {
                $check->setOutput($this->output);
            }

            $this->section($check->name());
            $result = $check->run($context);
            $this->displayCheckResult($result);
        }
    }

    /**
     * Display results from a check.
     */
    protected function displayCheckResult(CheckResult $result): void
    {
        foreach ($result->assertions as $assertion) {
            if ($assertion['passed']) {
                $this->testsPassed++;
                $this->line("  <fg=green>✓</> {$assertion['description']}");
            } else {
                $this->testsFailed++;
                $this->failures[] = $assertion['description'];
                $this->line("  <fg=red>✗</> {$assertion['description']}");
            }
        }
    }

    /**
     * Run cleanup verification check after cleanup completes.
     */
    protected function runCleanupVerification(DoctorContext $context): void
    {
        $check = new CleanupVerificationCheck();
        $this->section($check->name());
        $result = $check->run($context);
        $this->displayCheckResult($result);
    }

    /**
     * Display the command header banner.
     */
    protected function displayHeader(): void
    {
        $this->info('╔═══════════════════════════════════════════════════════════════╗');
        $this->info('║              Hypervel Cache - System Doctor                   ║');
        $this->info('╚═══════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * Display system and environment information.
     */
    protected function displaySystemInformation(): void
    {
        $this->info('System Information');
        $this->info('──────────────────────────────────────────────────────────────');

        // PHP Version
        $this->line('  PHP Version: <fg=cyan>' . PHP_VERSION . '</>');

        // PHPRedis Extension Version
        if (extension_loaded('redis')) {
            $this->line('  PHPRedis Version: <fg=cyan>' . phpversion('redis') . '</>');
        } else {
            $this->line('  PHPRedis Version: <fg=red>Not installed</>');
        }

        // Framework Version
        $this->line('  Framework: <fg=cyan>Hypervel</>');

        // Cache Store
        $config = $this->app->get(ConfigInterface::class);
        $defaultStore = $config->get('cache.default', 'file');
        $this->line("  Default Cache Store: <fg=cyan>{$defaultStore}</>");

        // Redis/Valkey Service
        try {
            $storeName = $this->option('store') ?: $this->detectRedisStore();

            if ($storeName) {
                $connectionName = $config->get("cache.stores.{$storeName}.connection", 'default');
                $repository = $this->app->get(CacheContract::class)->store($storeName);
                $store = $repository->getStore();

                if ($store instanceof RedisStore) {
                    $context = $store->getContext();
                    $info = $context->withConnection(
                        fn (RedisConnection $conn) => $conn->info('server')
                    );

                    if (isset($info['valkey_version'])) {
                        $this->line('  Service: <fg=cyan>Valkey</>');
                        $this->line("  Service Version: <fg=cyan>{$info['valkey_version']}</>");
                    } elseif (isset($info['redis_version'])) {
                        $this->line('  Service: <fg=cyan>Redis</>');
                        $this->line("  Service Version: <fg=cyan>{$info['redis_version']}</>");
                    }

                    $this->line('  Tag Mode: <fg=cyan>' . $store->getTagMode()->value . '</>');
                }
            }
        } catch (Exception) {
            $this->line('  Service: <fg=red>Connection failed</>');
        }

        $this->newLine(2);
    }

    /**
     * Clean up test data created during doctor checks.
     */
    protected function cleanup(DoctorContext $context, bool $silent = false): void
    {
        if (! $silent) {
            $this->newLine();
            $this->info('Cleaning up test data...');
        }

        // Flush all test tags (this handles most tagged items)
        $testTags = [
            'products', 'posts', 'featured', 'user:123', 'counters', 'unique',
            'permanent', 'bulk', 'color:red', 'color:blue', 'color:yellow',
            'complex', 'verify', 'leak-test', 'alpha', 'beta', 'cleanup',
            'large-set', 'rapid', 'overlap1', 'overlap2', '123', 'string-tag',
            'remember', 'concurrent-test',
        ];

        foreach ($testTags as $tag) {
            try {
                $context->cache->tags([$context->prefixed($tag)])->flush();
            } catch (Exception) {
                // Ignore cleanup errors
            }
        }

        // Delete individual test cache values by pattern (mode-aware)
        foreach ($context->getCacheValuePatterns(self::TEST_PREFIX) as $pattern) {
            try {
                $this->flushKeysByPattern($context->store, $pattern);
            } catch (Exception) {
                // Ignore cleanup errors
            }
        }

        // Delete tag storage structures for dynamically-created test tags (mode-aware)
        // e.g., tagA-{random}, tagB-{random} from SharedTagFlushCheck
        try {
            $this->flushKeysByPattern($context->store, $context->getTagStoragePattern(self::TEST_PREFIX));
        } catch (Exception) {
            // Ignore cleanup errors
        }

        // Any mode: clean up test entries from the tag registry
        if ($context->isAnyMode()) {
            try {
                $registryKey = $context->store->getContext()->registryKey();
                // Get all members matching the test prefix and remove them
                $members = $context->redis->zRange($registryKey, 0, -1);
                $testMembers = array_filter(
                    $members,
                    fn ($m) => str_starts_with($m, self::TEST_PREFIX)
                );
                if (! empty($testMembers)) {
                    $context->redis->zRem($registryKey, ...$testMembers);
                }
                // If registry is now empty, delete it
                if ($context->redis->zCard($registryKey) === 0) {
                    $context->redis->del($registryKey);
                }
            } catch (Exception) {
                // Ignore cleanup errors
            }
        }

        if (! $silent) {
            $this->info('Cleanup complete.');
        }
    }

    /**
     * Display a section header for a check group.
     */
    protected function section(string $title): void
    {
        $this->newLine();
        $this->info("┌─ {$title}");
    }

    /**
     * Display the final test summary with pass/fail counts.
     */
    protected function displaySummary(): void
    {
        $this->newLine(2);
        $this->info('═══════════════════════════════════════════════════════════════');

        if ($this->testsFailed === 0) {
            $this->info("<fg=green;options=bold>✓ ALL TESTS PASSED ({$this->testsPassed} tests)</>");
        } else {
            $this->error("✗ {$this->testsFailed} TEST(S) FAILED (out of " . ($this->testsPassed + $this->testsFailed) . ' total)');
            $this->newLine();
            $this->error('Failed tests:');

            foreach ($this->failures as $failure) {
                $this->error("  - {$failure}");
            }
        }

        $this->info('═══════════════════════════════════════════════════════════════');
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['store', null, InputOption::VALUE_OPTIONAL, 'The cache store to test (defaults to detecting redis driver)'],
        ];
    }
}
