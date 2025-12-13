<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console;

use Exception;
use Hyperf\Command\Command;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Cache\Contracts\Factory as CacheContract;
use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Console\Benchmark\ResultsFormatter;
use Hypervel\Cache\Redis\Console\Benchmark\ScenarioResult;
use Hypervel\Cache\Redis\Console\Benchmark\Scenarios\BulkWriteScenario;
use Hypervel\Cache\Redis\Console\Benchmark\Scenarios\CleanupScenario;
use Hypervel\Cache\Redis\Console\Benchmark\Scenarios\DeepTaggingScenario;
use Hypervel\Cache\Redis\Console\Benchmark\Scenarios\HeavyTaggingScenario;
use Hypervel\Cache\Redis\Console\Benchmark\Scenarios\NonTaggedScenario;
use Hypervel\Cache\Redis\Console\Benchmark\Scenarios\ReadPerformanceScenario;
use Hypervel\Cache\Redis\Console\Benchmark\Scenarios\ScenarioInterface;
use Hypervel\Cache\Redis\Console\Benchmark\Scenarios\StandardTaggingScenario;
use Hypervel\Cache\Redis\Console\Concerns\DetectsRedisStore;
use Hypervel\Cache\Redis\Exceptions\BenchmarkMemoryException;
use Hypervel\Cache\Redis\Support\MonitoringDetector;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Cache\RedisStore;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\SystemInfo;
use Hypervel\Support\Traits\HasLaravelStyleCommand;
use Symfony\Component\Console\Input\InputOption;

class BenchmarkCommand extends Command
{
    use DetectsRedisStore;
    use HasLaravelStyleCommand;

    /**
     * The console command name.
     */
    protected ?string $name = 'cache:redis-benchmark';

    /**
     * The console command description.
     */
    protected string $description = 'Run a comprehensive performance benchmark for Hypervel Redis Cache';

    /**
     * Scale configurations.
     *
     * @var array<string, array{items: int, tags_per_item: int, heavy_tags: int}>
     */
    protected array $scales = [
        'small' => ['items' => 1000, 'tags_per_item' => 3, 'heavy_tags' => 10],
        'medium' => ['items' => 10000, 'tags_per_item' => 5, 'heavy_tags' => 20],
        'large' => ['items' => 100000, 'tags_per_item' => 5, 'heavy_tags' => 50],
        'extreme' => ['items' => 1000000, 'tags_per_item' => 5, 'heavy_tags' => 50],
    ];

    /**
     * Recommended memory limits in MB per scale.
     *
     * @var array<string, int>
     */
    protected array $recommendedMemory = [
        'small' => 256,
        'medium' => 512,
        'large' => 1024,
        'extreme' => 2048,
    ];

    protected string $storeName;

    private ResultsFormatter $formatter;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displayHeader();
        $this->formatter = new ResultsFormatter($this);

        // 1. Validate options early
        $scale = $this->option('scale');

        if (! isset($this->scales[$scale])) {
            $this->error("Invalid scale: {$scale}. Available: " . implode(', ', array_keys($this->scales)));

            return self::FAILURE;
        }

        $runs = (int) $this->option('runs');

        if ($runs < 1 || $runs > 10) {
            $this->error("Invalid runs: {$runs}. Must be between 1 and 10.");

            return self::FAILURE;
        }

        // Validate tag mode if provided
        $tagModeOption = $this->option('tag-mode');

        if ($tagModeOption !== null && ! in_array($tagModeOption, ['all', 'any'], true)) {
            $this->error("Invalid tag mode: {$tagModeOption}. Available: all, any");

            return self::FAILURE;
        }

        // 2. Setup & Validation
        if (! $this->setup()) {
            return self::FAILURE;
        }

        // 3. Check for monitoring tools
        if (! $this->checkMonitoringTools()) {
            return self::FAILURE;
        }

        // 4. Display System Information
        $this->displaySystemInfo();

        // 5. Check memory requirements
        $this->checkMemoryRequirements($scale);

        // 6. Safety Check
        if (! $this->confirmSafeToRun()) {
            $this->info('Benchmark cancelled.');

            return self::SUCCESS;
        }

        $config = $this->scales[$scale];
        $runsText = $runs > 1 ? " averaging {$runs} runs" : '';
        $this->info("Running benchmark at <fg=cyan>{$scale}</> scale ({$config['items']} items){$runsText}.");
        $this->newLine();

        $cacheManager = $this->app->get(CacheContract::class);
        $ctx = $this->createContext($config, $cacheManager);

        try {
            // Run Benchmark(s)
            if ($this->option('compare-tag-modes')) {
                $this->runComparison($ctx, $runs);
            } else {
                // Use provided tag mode or current config
                $store = $ctx->getStoreInstance();
                $tagMode = $tagModeOption ?? $store->getTagMode()->value;
                $this->runSuiteWithRuns($tagMode, $ctx, $runs);
            }
        } catch (BenchmarkMemoryException $e) {
            $this->displayMemoryError($e);

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Cleaning up benchmark data...');
        $ctx->cleanup();

        return self::SUCCESS;
    }

    /**
     * Get the list of scenarios to run.
     *
     * @return array<ScenarioInterface>
     */
    protected function getScenarios(): array
    {
        return [
            new NonTaggedScenario(),
            new StandardTaggingScenario(),
            new HeavyTaggingScenario(),
            new DeepTaggingScenario(),
            new CleanupScenario(),
            new BulkWriteScenario(),
            new ReadPerformanceScenario(),
        ];
    }

    /**
     * Create a benchmark context with the given configuration.
     */
    protected function createContext(array $config, CacheContract $cacheManager): BenchmarkContext
    {
        return new BenchmarkContext(
            storeName: $this->storeName,
            items: $config['items'],
            tagsPerItem: $config['tags_per_item'],
            heavyTags: $config['heavy_tags'],
            command: $this,
            cacheManager: $cacheManager,
        );
    }

    /**
     * Validate options and detect the redis store.
     */
    protected function setup(): bool
    {
        $this->storeName = $this->option('store') ?? $this->detectRedisStore();

        if (! $this->storeName) {
            $this->error('Could not detect a cache store using the "redis" driver.');

            return false;
        }

        $cacheManager = $this->app->get(CacheContract::class);

        try {
            $storeInstance = $cacheManager->store($this->storeName)->getStore();

            if (! $storeInstance instanceof RedisStore) {
                $this->error("The cache store '{$this->storeName}' is not using the 'redis' driver.");
                $this->error('Found: ' . $storeInstance::class);

                return false;
            }

            // Test connection
            $cacheManager->store($this->storeName)->get('test');
        } catch (Exception $e) {
            $this->error("Could not connect to Redis store '{$this->storeName}': " . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Check for active monitoring tools that could skew results.
     */
    protected function checkMonitoringTools(): bool
    {
        $config = $this->app->get(ConfigInterface::class);
        $monitoringTools = (new MonitoringDetector($config))->detect();

        if (! empty($monitoringTools) && ! $this->option('force')) {
            $this->newLine();
            $this->error('Monitoring/profiling tools detected that will skew benchmark results:');
            $this->newLine();

            foreach ($monitoringTools as $tool => $howToDisable) {
                $this->line("  • <fg=yellow>{$tool}</> - set {$howToDisable}");
            }

            $this->newLine();
            $this->line('These tools intercept every cache operation, adding overhead that does not');
            $this->line('exist in production. They also consume significant memory.');
            $this->newLine();
            $this->line('Either disable these tools, or run with <fg=cyan>--force</> to benchmark anyway.');

            return false;
        }

        if (! empty($monitoringTools) && $this->option('force')) {
            $this->newLine();
            $this->warn('Running with --force despite monitoring tools being active.');
            $this->warn('   Results will be slower than production performance.');
            $this->newLine();
        }

        return true;
    }

    /**
     * Prompt user to confirm running the benchmark.
     */
    protected function confirmSafeToRun(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $config = $this->app->get(ConfigInterface::class);
        $env = $config->get('app.env', 'production');
        $scale = $this->option('scale');

        $this->warn('WARNING: This benchmark will put EXTREME load on your Redis instance');
        $this->newLine();

        $this->line('This command will:');
        $this->line('  - Create thousands/millions of cache keys');
        $this->line('  - Perform intensive flush operations');
        $this->line('  - Use significant CPU and memory');
        $this->line('  - Potentially impact other applications using the same Redis instance');
        $this->newLine();

        if ($env === 'production') {
            $this->error('PRODUCTION ENVIRONMENT DETECTED!');
            $this->error('Running this benchmark on production is STRONGLY DISCOURAGED.');
            $this->newLine();
        }

        $this->line("Scale: <fg=cyan>{$scale}</>");
        $this->newLine();

        $this->line('Recommendations:');
        $this->line('  - Run on development/staging environment only');
        $this->line('  - Use a dedicated Redis instance for benchmarking');
        $this->newLine();

        return $this->confirm('Do you want to proceed with the benchmark?', false);
    }

    /**
     * Run benchmark comparison between all and any tag modes.
     */
    protected function runComparison(BenchmarkContext $ctx, int $runs): void
    {
        $this->info('Running comparison between <fg=yellow>All</> and <fg=yellow>Any</> tag modes...');
        $this->newLine();

        $this->info('--- Phase 1: All Mode (Intersection) ---');
        $allResults = $this->runSuiteWithRuns('all', $ctx, $runs, returnResults: true);

        $this->newLine();
        $this->info('--- Phase 2: Any Mode (Union) ---');
        $anyResults = $this->runSuiteWithRuns('any', $ctx, $runs, returnResults: true);

        $this->formatter->displayComparisonTable($allResults, $anyResults);
    }

    /**
     * Run benchmark suite multiple times and average results.
     *
     * @return array<string, ScenarioResult>
     */
    protected function runSuiteWithRuns(string $tagMode, BenchmarkContext $ctx, int $runs, bool $returnResults = false): array
    {
        /** @var array<int, array<string, ScenarioResult>> $allRunResults */
        $allRunResults = [];

        for ($run = 1; $run <= $runs; ++$run) {
            if ($runs > 1) {
                $this->line("<fg=yellow>Run {$run}/{$runs}</>");
            }

            $results = $this->runSuite($tagMode, $ctx);
            $allRunResults[] = $results;

            if ($run < $runs) {
                $this->newLine();
                $this->line('<fg=gray>Pausing 1 second before next run...</>');
                $this->newLine();
                sleep(1);
            }
        }

        $averagedResults = $this->averageResults($allRunResults);

        if (! $returnResults) {
            $this->formatter->displayResultsTable($averagedResults, $tagMode);
        }

        return $averagedResults;
    }

    /**
     * Run all benchmark scenarios once with specified tag mode.
     *
     * @return array<string, ScenarioResult>
     */
    protected function runSuite(string $tagMode, BenchmarkContext $ctx): array
    {
        // Set the tag mode on the store
        $store = $ctx->getStoreInstance();
        $store->setTagMode(TagMode::fromConfig($tagMode));

        $this->line("Tag Mode: <fg=green>{$tagMode}</>");

        $results = [];

        foreach ($this->getScenarios() as $scenario) {
            $key = $this->scenarioKey($scenario);
            $result = $scenario->run($ctx);
            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * Average results from multiple runs.
     *
     * @param array<int, array<string, ScenarioResult>> $allRunResults
     * @return array<string, ScenarioResult>
     */
    protected function averageResults(array $allRunResults): array
    {
        if (count($allRunResults) === 1) {
            return $allRunResults[0];
        }

        $averaged = [];

        // Get all scenario keys from first run
        foreach (array_keys($allRunResults[0]) as $scenarioKey) {
            $metrics = [];

            // Collect metrics from all runs for this scenario
            foreach ($allRunResults as $runResult) {
                if (isset($runResult[$scenarioKey])) {
                    foreach ($runResult[$scenarioKey]->toArray() as $metricKey => $value) {
                        $metrics[$metricKey][] = $value;
                    }
                }
            }

            // Average each metric
            $averagedMetrics = [];

            foreach ($metrics as $metricKey => $values) {
                $averagedMetrics[$metricKey] = array_sum($values) / count($values);
            }

            $averaged[$scenarioKey] = new ScenarioResult($averagedMetrics);
        }

        return $averaged;
    }

    /**
     * Get the result key for a scenario.
     */
    private function scenarioKey(ScenarioInterface $scenario): string
    {
        return match ($scenario::class) {
            NonTaggedScenario::class => 'nontagged',
            StandardTaggingScenario::class => 'standard',
            HeavyTaggingScenario::class => 'heavy',
            DeepTaggingScenario::class => 'deep',
            CleanupScenario::class => 'cleanup',
            BulkWriteScenario::class => 'bulk',
            ReadPerformanceScenario::class => 'read',
            default => strtolower(basename(str_replace('\\', '/', $scenario::class))),
        };
    }

    /**
     * Display the command header banner.
     */
    protected function displayHeader(): void
    {
        $this->newLine();
        $this->info('╔═══════════════════════════════════════════════════════════════╗');
        $this->info('║       Hypervel Redis Cache - Performance Benchmark            ║');
        $this->info('╚═══════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * Display system and environment information.
     */
    protected function displaySystemInfo(): void
    {
        $systemInfo = new SystemInfo();

        $this->info('System Information');
        $this->line(str_repeat('─', 63));

        $os = PHP_OS_FAMILY;
        $osVersion = php_uname('r');
        $this->line("  OS: <fg=cyan>{$os} {$osVersion}</>");

        $arch = php_uname('m');
        $this->line("  Architecture: <fg=cyan>{$arch}</>");

        $phpVersion = PHP_VERSION;
        $this->line("  PHP: <fg=cyan>{$phpVersion}</>");

        $cpuCores = $systemInfo->getCpuCores();

        if ($cpuCores) {
            $this->line("  CPU Cores: <fg=cyan>{$cpuCores}</>");
        }

        $totalMemory = $systemInfo->getTotalMemory();

        if ($totalMemory) {
            $this->line("  Total Memory: <fg=cyan>{$totalMemory}</>");
        }

        $memoryLimit = $systemInfo->getMemoryLimitFormatted();
        $this->line("  PHP Memory Limit: <fg=cyan>{$memoryLimit}</>");

        $vmType = $systemInfo->detectVirtualization();

        if ($vmType) {
            $this->line("  Virtualization: <fg=cyan>{$vmType}</>");
        }

        // Display Redis/Valkey info
        $cacheManager = $this->app->get(CacheContract::class);

        try {
            $store = $cacheManager->store($this->storeName)->getStore();

            if ($store instanceof RedisStore) {
                $context = $store->getContext();
                $info = $context->withConnection(
                    fn (RedisConnection $conn) => $conn->info('server')
                );

                if (isset($info['valkey_version'])) {
                    $this->line("  Cache Service: <fg=cyan>Valkey {$info['valkey_version']}</>");
                } elseif (isset($info['redis_version'])) {
                    $this->line("  Cache Service: <fg=cyan>Redis {$info['redis_version']}</>");
                }

                $this->line('  Tag Mode: <fg=cyan>' . $store->getTagMode()->value . '</>');
            }
        } catch (Exception) {
            // Silently skip if Redis connection fails
        }

        $this->newLine();
    }

    /**
     * Warn if memory limit is below recommended for the scale.
     */
    protected function checkMemoryRequirements(string $scale): void
    {
        $recommended = $this->recommendedMemory[$scale] ?? 256;
        $currentLimitBytes = (new SystemInfo())->getMemoryLimitBytes();

        if ($currentLimitBytes === -1) {
            return;
        }

        $currentLimitMB = (int) ($currentLimitBytes / 1024 / 1024);

        if ($currentLimitMB < $recommended) {
            $this->warn("Memory limit ({$currentLimitMB}MB) is below recommended ({$recommended}MB) for '{$scale}' scale.");
            $this->line('   Consider: <fg=cyan>php -d memory_limit=' . $recommended . 'M bin/hyperf.php cache:redis-benchmark</>');
            $this->newLine();
        }
    }

    /**
     * Display memory exhaustion error with recovery guidance.
     */
    protected function displayMemoryError(BenchmarkMemoryException $e): void
    {
        $config = $this->app->get(ConfigInterface::class);

        $this->newLine();
        $this->error('Benchmark aborted due to memory constraints.');
        $this->newLine();
        $this->line($e->getMessage());
        $this->newLine();
        $this->warn('Cleanup skipped to avoid further memory exhaustion.');
        $this->line('   After fixing memory issues, clean up leftover benchmark keys:');
        $this->newLine();
        $this->line('   Option 1 - Clear all cache (simple):');
        $this->line('   <fg=cyan>php bin/hyperf.php cache:clear --store=' . $this->storeName . '</>');
        $this->newLine();
        $this->line('   Option 2 - Clear only benchmark keys (preserves other cache):');
        $cachePrefix = $config->get("cache.stores.{$this->storeName}.prefix", $config->get('cache.prefix', ''));
        $this->line('   <fg=cyan>redis-cli KEYS "' . $cachePrefix . BenchmarkContext::KEY_PREFIX . '*" | xargs redis-cli DEL</>');
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['scale', null, InputOption::VALUE_OPTIONAL, 'Scale of the benchmark (small, medium, large, extreme)', 'medium'],
            ['tag-mode', null, InputOption::VALUE_OPTIONAL, 'Tag mode to test (all, any). Defaults to current config.'],
            ['compare-tag-modes', null, InputOption::VALUE_NONE, 'Run benchmark in both tag modes and compare results'],
            ['store', null, InputOption::VALUE_OPTIONAL, 'The cache store to use (defaults to detecting redis driver)'],
            ['runs', null, InputOption::VALUE_OPTIONAL, 'Number of runs to average (default: 3)', '3'],
            ['force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt'],
        ];
    }
}
