#!/usr/bin/env php
<?php

declare(strict_types=1);

use Hypervel\Contracts\Config\Repository;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\SlidingWindow;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/tests/bootstrap.php';

Bootstrapper::bootstrap();

class RateLimiterBenchmark
{
    /**
     * Create a new rate limiter benchmark.
     *
     * @param list<string> $stores
     */
    public function __construct(
        private readonly Repository $config,
        private readonly RateLimiter $manager,
        private readonly array $stores,
        private readonly int $operations,
        private readonly int $concurrency,
        private readonly int $warmup,
        private readonly string $runId,
    ) {
    }

    /**
     * Run every configured benchmark scenario.
     */
    public function execute(): void
    {
        $this->printEnvironment();

        foreach ($this->stores as $storeName) {
            $limiter = $this->manager->store($storeName);

            printf("\nStore: %s\n", $storeName);
            printf("Backend: %s\n", $this->describeBackend($storeName, $limiter));
            printf(
                "%-14s %-8s %8s %14s %12s %12s %12s\n",
                'policy',
                'path',
                'clients',
                'operations/s',
                'p50 us',
                'p95 us',
                'p99 us',
            );

            foreach (['fixed-window', 'sliding-window', 'leaky-bucket'] as $policyName) {
                foreach (['allowed', 'denied'] as $path) {
                    foreach (array_values(array_unique([1, $this->concurrency])) as $clients) {
                        $this->benchmarkScenario($limiter, $storeName, $policyName, $path, $clients);
                    }
                }
            }
        }
    }

    /**
     * Benchmark one policy, result path, and client count.
     */
    private function benchmarkScenario(
        Limiter $limiter,
        string $storeName,
        string $policyName,
        string $path,
        int $clients,
    ): void {
        $expectedAllowed = $path === 'allowed';
        $key = implode(':', [$this->runId, $storeName, $policyName, $path, (string) $clients]);
        $policy = $this->makePolicy($policyName, $expectedAllowed, $key);

        $limiter->clear($policy);

        try {
            if (! $expectedAllowed) {
                $this->consume($limiter, $policy, true);
            }

            for ($operation = 0; $operation < $this->warmup; ++$operation) {
                $this->consume($limiter, $policy, $expectedAllowed);
            }

            $startedAt = hrtime(true);
            $samples = $this->runOperations($limiter, $policy, $expectedAllowed, $clients);
            $elapsedNanoseconds = hrtime(true) - $startedAt;

            sort($samples, SORT_NUMERIC);

            printf(
                "%-14s %-8s %8d %14.0f %12.2f %12.2f %12.2f\n",
                $policyName,
                $path,
                $clients,
                $this->operations / ($elapsedNanoseconds / 1_000_000_000),
                $this->percentile($samples, 0.50) / 1000,
                $this->percentile($samples, 0.95) / 1000,
                $this->percentile($samples, 0.99) / 1000,
            );
        } finally {
            $limiter->clear($policy);
        }
    }

    /**
     * Create the policy for one benchmark path.
     */
    private function makePolicy(string $policyName, bool $expectedAllowed, string $key): AdmissionPolicy
    {
        if (! $expectedAllowed) {
            return match ($policyName) {
                'fixed-window' => Limit::perDay(1)->by($key),
                'sliding-window' => SlidingWindow::perDay(1)->by($key),
                'leaky-bucket' => LeakyBucket::perDay(1)->burst(1)->by($key),
                default => throw new LogicException("Unknown benchmark policy [{$policyName}]."),
            };
        }

        $capacity = $this->operations + $this->warmup + 1;

        return match ($policyName) {
            'fixed-window' => Limit::perMinute($capacity)->by($key),
            'sliding-window' => SlidingWindow::perMinute($capacity)->by($key),
            'leaky-bucket' => LeakyBucket::perSecond(min($capacity, 1_000_000))
                ->burst($capacity)
                ->by($key),
            default => throw new LogicException("Unknown benchmark policy [{$policyName}]."),
        };
    }

    /**
     * Run the requested operations across the requested number of clients.
     *
     * @return list<int> per-operation latency samples in nanoseconds
     */
    private function runOperations(
        Limiter $limiter,
        AdmissionPolicy $policy,
        bool $expectedAllowed,
        int $clients,
    ): array {
        if ($clients === 1) {
            return $this->runClient($limiter, $policy, $expectedAllowed, $this->operations);
        }

        $callbacks = [];
        $baseOperations = intdiv($this->operations, $clients);
        $remainder = $this->operations % $clients;

        for ($client = 0; $client < $clients; ++$client) {
            $clientOperations = $baseOperations + ($client < $remainder ? 1 : 0);
            $callbacks[] = fn (): array => $this->runClient(
                $limiter,
                $policy,
                $expectedAllowed,
                $clientOperations,
            );
        }

        $samples = [];

        foreach (parallel($callbacks, $clients) as $clientSamples) {
            array_push($samples, ...$clientSamples);
        }

        return $samples;
    }

    /**
     * Run one client's share of the workload.
     *
     * @return list<int> per-operation latency samples in nanoseconds
     */
    private function runClient(
        Limiter $limiter,
        AdmissionPolicy $policy,
        bool $expectedAllowed,
        int $operations,
    ): array {
        $samples = [];

        for ($operation = 0; $operation < $operations; ++$operation) {
            $startedAt = hrtime(true);
            $this->consume($limiter, $policy, $expectedAllowed);
            $samples[] = hrtime(true) - $startedAt;
        }

        return $samples;
    }

    /**
     * Consume one policy and exercise the middleware-relevant result fields.
     */
    private function consume(Limiter $limiter, AdmissionPolicy $policy, bool $expectedAllowed): void
    {
        $result = $limiter->consume($policy);

        if ($result->allowed() !== $expectedAllowed) {
            throw new LogicException(sprintf(
                'Expected the benchmark operation to be %s, but it was %s.',
                $expectedAllowed ? 'allowed' : 'denied',
                $result->allowed() ? 'allowed' : 'denied',
            ));
        }

        $result->remaining();
        $result->retryAfter();
    }

    /**
     * Return the nearest-rank percentile from sorted nanosecond samples.
     *
     * @param list<int> $samples
     */
    private function percentile(array $samples, float $percentile): int
    {
        $index = (int) ceil(count($samples) * $percentile) - 1;

        return $samples[max(0, min(count($samples) - 1, $index))];
    }

    /**
     * Print the reproducibility inputs for this run.
     */
    private function printEnvironment(): void
    {
        printf("Hypervel rate limiter benchmark\n");
        printf("Timestamp: %s\n", gmdate(DATE_ATOM));
        printf("PHP: %s\n", PHP_VERSION);
        printf("Swoole: %s\n", phpversion('swoole') ?: 'not loaded');
        printf("Operations per row: %d\n", $this->operations);
        printf("Warmup operations per row: %d\n", $this->warmup);
        printf("Concurrent clients: %d\n", $this->concurrency);
        printf("Rate limiter prefix: %s\n", $this->config->string('rate-limiter.prefix'));
    }

    /**
     * Describe the non-secret backend inputs for one store.
     */
    private function describeBackend(string $storeName, Limiter $limiter): string
    {
        $storeConfig = $this->config->get("rate-limiter.stores.{$storeName}");

        if (! is_array($storeConfig) || ! is_string($storeConfig['driver'] ?? null)) {
            throw new RuntimeException("Rate limiter store [{$storeName}] has invalid benchmark configuration.");
        }

        $driver = $storeConfig['driver'];
        $details = match ($driver) {
            'redis' => $this->describeRedis($storeConfig),
            'database' => $this->describeDatabase($storeConfig),
            'swoole' => sprintf(
                'rows=%s conflict_proportion=%s',
                (string) ($storeConfig['rows'] ?? 'unknown'),
                (string) ($storeConfig['conflict_proportion'] ?? 'unknown'),
            ),
            default => '',
        };

        return trim(sprintf('%s driver=%s %s', $limiter->getStore()::class, $driver, $details));
    }

    /**
     * Describe one Redis benchmark connection without exposing credentials.
     *
     * @param array<string, mixed> $storeConfig
     */
    private function describeRedis(array $storeConfig): string
    {
        $connectionName = $storeConfig['connection'] ?? null;

        if (! is_string($connectionName) || $connectionName === '') {
            throw new RuntimeException('The Redis benchmark store requires a connection name.');
        }

        $connection = $this->config->get("database.redis.{$connectionName}");

        if (! is_array($connection)) {
            throw new RuntimeException("Redis connection [{$connectionName}] is not configured.");
        }

        return sprintf(
            'connection=%s host=%s port=%s database=%s',
            $connectionName,
            (string) ($connection['host'] ?? 'url'),
            (string) ($connection['port'] ?? 'url'),
            (string) ($connection['database'] ?? 'default'),
        );
    }

    /**
     * Describe one database benchmark connection without exposing credentials.
     *
     * @param array<string, mixed> $storeConfig
     */
    private function describeDatabase(array $storeConfig): string
    {
        $connectionName = $storeConfig['connection'] ?? $this->config->string('database.default');

        if (! is_string($connectionName) || $connectionName === '') {
            throw new RuntimeException('The database benchmark store requires a connection name.');
        }

        $connection = $this->config->get("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            throw new RuntimeException("Database connection [{$connectionName}] is not configured.");
        }

        return sprintf(
            'connection=%s driver=%s database=%s table=%s',
            $connectionName,
            (string) ($connection['driver'] ?? 'unknown'),
            (string) ($connection['database'] ?? 'url'),
            (string) ($storeConfig['table'] ?? 'unknown'),
        );
    }
}

/**
 * Run the benchmark CLI.
 */
function main(): int
{
    $options = getopt('', [
        'stores:',
        'operations:',
        'concurrency:',
        'warmup:',
        'database-connection:',
        'help',
    ]);

    if ($options === false) {
        fwrite(STDERR, "Unable to parse benchmark options.\n");

        return 1;
    }

    if (array_key_exists('help', $options)) {
        printUsage();

        return 0;
    }

    try {
        $stores = parseStores($options['stores'] ?? 'redis,swoole,database');
        $operations = parseIntegerOption($options, 'operations', 10_000, 1, 1_000_000);
        $concurrency = parseIntegerOption($options, 'concurrency', 16, 1, $operations);
        $warmup = parseIntegerOption($options, 'warmup', 100, 0, 100_000);

        $application = TestbenchApplication::create(
            options: ['load_environment_variables' => false],
        );

        try {
            $config = $application->make(Repository::class);
            $databaseConnection = $options['database-connection'] ?? null;

            if ($databaseConnection !== null) {
                if (! is_string($databaseConnection) || $databaseConnection === '') {
                    throw new InvalidArgumentException('--database-connection must be a non-empty string.');
                }

                $config->set('rate-limiter.stores.database.connection', $databaseConnection);
            }

            $runId = sprintf('%d-%s', getmypid(), bin2hex(random_bytes(6)));
            $config->set('rate-limiter.prefix', 'benchmark_rate_limiter_' . $runId);

            $benchmark = new RateLimiterBenchmark(
                $config,
                $application->make(RateLimiter::class),
                $stores,
                $operations,
                $concurrency,
                $warmup,
                $runId,
            );

            $executionException = null;

            $completed = run(function () use ($benchmark, &$executionException): void {
                try {
                    $benchmark->execute();
                } catch (Throwable $throwable) {
                    $executionException = $throwable;
                }
            });

            if ($executionException !== null) {
                throw $executionException;
            }

            if (! $completed) {
                throw new RuntimeException('The benchmark coroutine did not complete.');
            }
        } finally {
            $application->terminate();
        }
    } catch (Throwable $throwable) {
        fwrite(STDERR, sprintf("Benchmark failed: %s: %s\n", $throwable::class, $throwable->getMessage()));

        return 1;
    }

    return 0;
}

/**
 * Parse a comma-separated store list.
 *
 * @return list<string>
 */
function parseStores(mixed $value): array
{
    if (! is_string($value)) {
        throw new InvalidArgumentException('--stores must be a comma-separated string.');
    }

    $stores = array_values(array_unique(array_filter(
        array_map(trim(...), explode(',', $value)),
        static fn (string $store): bool => $store !== '',
    )));

    if ($stores === []) {
        throw new InvalidArgumentException('--stores must contain at least one store name.');
    }

    return $stores;
}

/**
 * Parse and validate one integer option.
 *
 * @param array<string, array<int, string>|false|string> $options
 */
function parseIntegerOption(array $options, string $name, int $default, int $minimum, int $maximum): int
{
    $value = $options[$name] ?? (string) $default;

    if (! is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException("--{$name} must be an integer.");
    }

    $value = (int) $value;

    if ($value < $minimum || $value > $maximum) {
        throw new InvalidArgumentException("--{$name} must be between {$minimum} and {$maximum}.");
    }

    return $value;
}

/**
 * Print command usage.
 */
function printUsage(): void
{
    echo <<<'TEXT'
Usage: php tests/Benchmarks/RateLimiter/benchmark.php [options]

Options:
  --stores=LIST                  Comma-separated configured stores (default: redis,swoole,database)
  --operations=COUNT             Operations measured per output row (default: 10000)
  --concurrency=COUNT            Clients used for the concurrent rows (default: 16)
  --warmup=COUNT                 Unmeasured warmup operations per row (default: 100)
  --database-connection=NAME     Database connection used by the database limiter store
  --help                         Show this help

TEXT;
}

exit(main());
