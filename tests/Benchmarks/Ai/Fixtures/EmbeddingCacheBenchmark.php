<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\Ai\Fixtures;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\RedisTestConfiguration;
use Hypervel\Support\Facades\Cache;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Redis as PhpRedis;

class EmbeddingCacheBenchmark extends TestCase
{
    use InteractsWithRedis;

    /**
     * Require an explicit benchmark run against a disposable Redis instance.
     */
    protected function setUp(): void
    {
        if (getenv('BENCH_SMOKE') !== '1' && getenv('BENCH_IDLE_CONFIRMED') !== '1') {
            $this->fail('Use BENCH_SMOKE=1 or BENCH_IDLE_CONFIRMED=1.');
        }

        if (! getenv('BENCH_REDIS_PORT')) {
            $this->fail('Set BENCH_REDIS_PORT to a disposable loopback Redis instance; its test database is flushed.');
        }

        parent::setUp();
    }

    /**
     * Route cache operations to the explicitly supplied disposable instance.
     */
    protected function defineEnvironment(Application $app): void
    {
        // Environment files are loaded here, before the Redis trait configures and flushes connections.
        $this->assertFalse(RedisTestConfiguration::usesCluster(), 'This benchmark requires standalone Redis; unset REDIS_CLUSTER_HOSTS_AND_PORTS.');

        $config = $app->make('config');
        $connection = $config->array('database.redis.default');
        $config->set([
            'database.redis' => [
                'options' => ['prefix' => ''],
                'default' => array_replace($connection, [
                    'url' => null,
                    'host' => '127.0.0.1',
                    'port' => (int) getenv('BENCH_REDIS_PORT'),
                    'username' => null,
                    'password' => null,
                    'options' => ['prefix' => ''],
                ]),
            ],
            'ai.providers.openai' => ['driver' => 'openai', 'key' => 'benchmark-key'],
            'ai.caching.embeddings.store' => 'benchmark',
        ]);
    }

    #[DataProvider('cacheModes')]
    public function testEmbeddingCache(int $count, bool $individual, string $state, bool $compressed): void
    {
        $compression = $compressed ? PhpRedis::COMPRESSION_LZ4 : PhpRedis::COMPRESSION_NONE;
        $connection = $this->createRedisConnectionWithOptions('benchmark', [
            'prefix' => '',
            'serializer' => PhpRedis::SERIALIZER_NONE,
            'compression' => $compression,
        ]);
        config(['cache.stores.benchmark' => ['driver' => 'redis', 'connection' => $connection, 'prefix' => 'ai-benchmark:']]);
        $redis = $this->redisClient($connection);
        $this->assertSame(PhpRedis::SERIALIZER_NONE, $redis->getOption(PhpRedis::OPT_SERIALIZER));
        $this->assertSame($compression, $redis->getOption(PhpRedis::OPT_COMPRESSION));
        $cache = Cache::store('benchmark');
        $random = new Randomizer(new Mt19937(1234));
        $vectors = [];

        for ($input = 0; $input < $count; ++$input) {
            $vector = [];

            for ($dimension = 0; $dimension < 1536; ++$dimension) {
                $vector[] = $random->getInt(-5000000, 5000000) / 100000000;
            }

            $vectors['Document ' . $input] = $vector;
        }

        $gateway = new class($vectors) implements EmbeddingGateway {
            public int $calls = 0;

            public array $lastInputs = [];

            /**
             * Keep deterministic vectors outside the measured generation path.
             *
             * @param array<string, list<float>> $vectors
             */
            public function __construct(private array $vectors)
            {
            }

            /**
             * Return local vectors without HTTP calls or retained fake history.
             */
            public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse
            {
                ++$this->calls;
                $this->lastInputs = $inputs;

                return new EmbeddingsResponse(
                    array_map(fn (string $input): array => $this->vectors[$input], $inputs),
                    new Usage(count($inputs)),
                    new Meta($provider->name(), $model),
                );
            }
        };
        $provider = app(AiManager::class)->embeddingProvider('openai')->useEmbeddingGateway($gateway);
        $inputs = array_keys($vectors);
        $generate = static fn (array $inputs): EmbeddingsResponse => Embeddings::for($inputs)->dimensions(1536)->cache(individually: $individual)->generate($provider, 'benchmark');
        $seedInputs = match ($state) {
            'hit' => $inputs,
            'partial' => array_slice($inputs, 0, intdiv($count, 2)),
            default => [],
        };
        $expectedMissing = array_slice($inputs, count($seedInputs));
        $generate($inputs);
        $smoke = getenv('BENCH_SMOKE') === '1';
        $iterations = $smoke ? 2 : ($count === 1 ? 500 : 20);
        $commands = [];

        for ($sample = 0; $sample < ($smoke ? 1 : 5); ++$sample) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $cpu = 0;
            $wall = 0;

            for ($iteration = 0; $iteration < $iterations; ++$iteration) {
                if ($state !== 'hit') {
                    $cache->clear();

                    if ($seedInputs !== []) {
                        $generate($seedInputs);
                    }
                }

                $calls = $gateway->calls;

                if ($sample === 0 && $iteration === 0) {
                    $before = self::commandCounts($redis->info('commandstats'));
                }

                $cpuBefore = self::cpuMicroseconds();
                $started = hrtime(true);
                $response = $generate($inputs);
                $wall += hrtime(true) - $started;
                $cpu += self::cpuMicroseconds() - $cpuBefore;

                if ($sample === 0 && $iteration === 0) {
                    foreach (self::commandCounts($redis->info('commandstats')) as $command => $total) {
                        $commands[$command] = $total - $before[$command];
                    }
                }

                $this->assertSame($state === 'hit' ? 0 : 1, $gateway->calls - $calls);

                if ($state !== 'hit') {
                    $this->assertSame($expectedMissing, $gateway->lastInputs);
                }
            }

            $this->assertSame(array_values($vectors), $response->embeddings);
            $this->assertSame(count($expectedMissing), $response->usage->inputTokens);
            $peakHeap = memory_get_peak_usage();
            $keys = $redis->keys('ai-benchmark:*');
            $this->assertCount($individual ? $count : 1, $keys);
            $storedBytes = array_sum(array_map(fn (string $key): int => $redis->strlen($key), $keys));

            file_put_contents((string) getenv('BENCH_OUT'), json_encode([
                'case' => $count . '.' . ($individual ? 'individual' : 'shared') . '.' . $state . '.' . ($compressed ? 'lz4' : 'plain'),
                'sample' => $sample,
                'iterations' => $iterations,
                'smoke' => $smoke,
                'dimensions' => 1536,
                'cpu_us_per_operation' => $cpu / $iterations,
                'wall_us_per_operation' => $wall / 1000 / $iterations,
                'stored_value_bytes' => $storedBytes,
                'redis_commands_per_operation' => $commands,
                'peak_heap' => $peakHeap,
            ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * Compare connection-level compression across full and partial cache hits.
     */
    public static function cacheModes(): array
    {
        $cases = [];

        foreach ([false, true] as $compressed) {
            foreach ([[1, true, 'hit'], [1, true, 'miss'], [100, true, 'hit'], [100, true, 'miss'], [100, true, 'partial'], [100, false, 'hit'], [100, false, 'miss']] as [$count, $individual, $state]) {
                $name = $count . ' ' . ($individual ? 'individual' : 'shared') . ' ' . $state . ' ' . ($compressed ? 'lz4' : 'plain');
                $cases[$name] = [$count, $individual, $state, $compressed];
            }
        }

        return $cases;
    }

    /**
     * Read relevant server command counts, including commands executed inside Lua.
     *
     * @param array<string, string> $statistics
     * @return array<string, int>
     */
    private static function commandCounts(array $statistics): array
    {
        $counts = [];

        foreach (['get', 'mget', 'setex', 'eval', 'evalsha'] as $command) {
            preg_match('/calls=(\d+)/', $statistics['cmdstat_' . $command] ?? '', $matches);
            $counts[$command] = (int) ($matches[1] ?? 0);
        }

        return $counts;
    }

    /**
     * Return process user and system CPU time in microseconds.
     */
    private static function cpuMicroseconds(): int
    {
        $usage = getrusage();

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000000 + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
    }
}
