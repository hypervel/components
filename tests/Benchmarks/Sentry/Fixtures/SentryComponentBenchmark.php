<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\Sentry\Fixtures;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Http\Request;
use Hypervel\Sentry\Hub;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Tests\Sentry\TestCase;
use ReflectionClass;
use Sentry\Breadcrumb;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Swoole\Coroutine as SwooleCoroutine;

// The baseline's Sentry test base has the same helpers under its old name.
if (! class_exists(TestCase::class)) {
    class_alias(\Hypervel\Tests\Sentry\SentryTestCase::class, TestCase::class);
}

/**
 * Component costs of Guzzle instrumentation and child-coroutine propagation, for baseline/current comparisons.
 *
 * BENCH_OUT receives one JSON line per case; BENCH_SMOKE=1 runs a few iterations to check the harness only.
 * Cases that need options the baseline lacks are skipped there.
 */
class SentryComponentBenchmark extends TestCase
{
    /**
     * Whether each Guzzle case records http.client spans, http breadcrumbs and span origins.
     *
     * @var array<string, array{spans: bool, breadcrumbs: bool, origin: bool}>
     */
    private const array GUZZLE_EXPECTATIONS = [
        'guzzle.sampled' => ['spans' => true, 'breadcrumbs' => true, 'origin' => false],
        'guzzle.unsampled' => ['spans' => false, 'breadcrumbs' => true, 'origin' => false],
        'guzzle.no_transaction' => ['spans' => false, 'breadcrumbs' => true, 'origin' => false],
        'guzzle.recording_off' => ['spans' => false, 'breadcrumbs' => false, 'origin' => false],
        'guzzle.opted_out' => ['spans' => false, 'breadcrumbs' => false, 'origin' => false],
        'guzzle.inactive' => ['spans' => false, 'breadcrumbs' => false, 'origin' => false],
        'guzzle.sampled.origin_off' => ['spans' => true, 'breadcrumbs' => true, 'origin' => false],
        'guzzle.sampled.origin_threshold_0' => ['spans' => true, 'breadcrumbs' => true, 'origin' => true],
        'guzzle.async.sampled' => ['spans' => true, 'breadcrumbs' => true, 'origin' => false],
        'guzzle.async.sampled.origin_off' => ['spans' => true, 'breadcrumbs' => true, 'origin' => false],
    ];

    /**
     * Configure each case at the test's only boot.
     *
     * Reloading within a test would register the coroutine propagation hook a second time. The harness counts
     * as application code so origins resolve as they would in an app.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        $sampled = ['sentry.traces_sample_rate' => 1.0];
        $originOff = ['http_client_requests_origin' => false];

        $this->setupConfig = ['sentry.in_app_include' => [__DIR__]] + match ($this->name()) {
            'testGuzzleRecordingOff' => $this->sentryConfig(
                $sampled,
                tracing: ['http_client_requests' => false],
                breadcrumbs: ['http_client_requests' => false],
            ),
            'testGuzzleSampledOriginOff', 'testGuzzleAsyncSampledOriginOff', 'testPendingAsyncMemorySampledOriginOff' => $this->sentryConfig($sampled, tracing: $originOff),
            'testGuzzleSampledOriginForEveryRequest' => $this->sentryConfig($sampled, tracing: ['http_client_requests_origin_threshold_ms' => 0]),
            'testGuzzleInactive', 'testPendingAsyncMemoryInactive', 'testChildCoroutinePropagationInactive' => ['sentry.dsn' => null],
            default => $sampled,
        };

        return parent::getPackageProviders($app);
    }

    public function testGuzzleSampled(): void
    {
        $this->measureGuzzle('guzzle.sampled', fn (): Transaction => $this->startTransaction());
    }

    public function testGuzzleUnsampled(): void
    {
        $this->measureGuzzle('guzzle.unsampled', fn (): Transaction => $this->startUnsampledTransaction());
    }

    public function testGuzzleWithoutTransaction(): void
    {
        $this->measureGuzzle('guzzle.no_transaction');
    }

    public function testGuzzleRecordingOff(): void
    {
        $this->measureGuzzle('guzzle.recording_off', fn (): Transaction => $this->startTransaction());
    }

    public function testGuzzleOptedOut(): void
    {
        // Each revision honors its own opt-out option and ignores the other.
        $this->measureGuzzle('guzzle.opted_out', fn (): Transaction => $this->startTransaction(), [
            'sentry_enabled' => false,
            'no_sentry_aspect' => true,
        ]);
    }

    public function testGuzzleInactive(): void
    {
        $this->measureGuzzle('guzzle.inactive');
    }

    public function testGuzzleSampledOriginOff(): void
    {
        $this->requireOriginOptions();
        $this->measureGuzzle('guzzle.sampled.origin_off', fn (): Transaction => $this->startTransaction());
    }

    public function testGuzzleSampledOriginForEveryRequest(): void
    {
        $this->requireOriginOptions();
        $this->measureGuzzle('guzzle.sampled.origin_threshold_0', fn (): Transaction => $this->startTransaction());
    }

    public function testGuzzleAsyncSampled(): void
    {
        $this->measureGuzzle('guzzle.async.sampled', fn (): Transaction => $this->startTransaction(), async: true);
    }

    public function testGuzzleAsyncSampledOriginOff(): void
    {
        $this->requireOriginOptions();
        $this->measureGuzzle('guzzle.async.sampled.origin_off', fn (): Transaction => $this->startTransaction(), async: true);
    }

    public function testPendingAsyncMemoryInactive(): void
    {
        $this->measurePendingMemory('guzzle.pending_memory.inactive');
    }

    public function testPendingAsyncMemorySampled(): void
    {
        $this->measurePendingMemory('guzzle.pending_memory.sampled', fn (): Transaction => $this->startTransaction());
    }

    public function testPendingAsyncMemorySampledOriginOff(): void
    {
        $this->requireOriginOptions();
        $this->measurePendingMemory('guzzle.pending_memory.sampled.origin_off', fn (): Transaction => $this->startTransaction());
    }

    public function testChildCoroutinePropagationActive(): void
    {
        $this->measurePropagation('propagation.active', fn (): Transaction => $this->startTransaction());
    }

    public function testChildCoroutinePropagationInactive(): void
    {
        $this->measurePropagation('propagation.inactive');
    }

    /**
     * Measure the time per Guzzle request through a MockHandler client.
     */
    private function measureGuzzle(string $case, ?callable $prepare = null, array $options = [], bool $async = false): void
    {
        // Warmup plus measured spans stay below the SDK's 1,000-span recorder limit, as in a real request.
        $requests = $this->iterations(500);
        $expected = self::GUZZLE_EXPECTATIONS[$case];
        $runs = [];

        for ($run = 0; $run < $this->runs(); ++$run) {
            $transaction = $prepare ? $prepare() : null;
            $client = $this->client($requests + 100);

            for ($warmup = 0; $warmup < 100; ++$warmup) {
                $client->get('https://example.com/warmup', $options);
            }

            $start = hrtime(true);

            for ($request = 0; $request < $requests; ++$request) {
                $async
                    ? $client->getAsync('https://example.com/path?page=5', $options)->wait()
                    : $client->get('https://example.com/path?page=5', $options);
            }

            $runs[] = (hrtime(true) - $start) / 1000 / $requests;

            $spans = $this->assertRecordedSpans($case, $transaction, $expected['spans'] ? 100 + $requests : 0);
            $origins = array_filter($spans, static fn (Span $span): bool => isset($span->getData()['code.filepath']));
            $this->assertSame($expected['origin'], $origins !== [], "{$case}: origin data");
        }

        $breadcrumbs = array_filter(
            $this->getCurrentSentryBreadcrumbs(),
            static fn (Breadcrumb $breadcrumb): bool => $breadcrumb->getCategory() === 'http',
        );
        $this->assertSame($expected['breadcrumbs'], $breadcrumbs !== [], "{$case}: breadcrumbs");

        $this->record($case, 'us_per_request', $runs, ['requests' => $requests]);
    }

    /**
     * Assert that the transaction recorded the expected number of finished HTTP client spans.
     *
     * @return list<Span>
     */
    private function assertRecordedSpans(string $case, ?Transaction $transaction, int $expected): array
    {
        $spans = array_values(array_filter(
            $transaction?->getSpanRecorder()?->getSpans() ?? [],
            static fn (Span $span): bool => $span->getOp() === 'http.client',
        ));

        $this->assertCount($expected, $spans, "{$case}: recorded spans");
        $this->assertSame([], array_filter($spans, static fn (Span $span): bool => $span->getEndTimestamp() === null), "{$case}: unfinished spans");

        return $spans;
    }

    /**
     * Measure the memory each outstanding asynchronous request retains.
     */
    private function measurePendingMemory(string $case, ?callable $prepare = null): void
    {
        // The warmup and pending spans stay below the SDK's 1,000-span recorder limit.
        $requests = $this->iterations(500);
        $runs = [];

        for ($run = 0; $run < $this->runs(); ++$run) {
            $transaction = $prepare ? $prepare() : null;
            $client = $this->client($requests + 100);
            $client->getAsync('https://example.com/warmup')->wait();
            gc_collect_cycles();
            $before = memory_get_usage();
            $promises = [];

            for ($request = 0; $request < $requests; ++$request) {
                $promises[] = $client->getAsync('https://example.com/path?page=5');
            }

            $runs[] = (memory_get_usage() - $before) / $requests;
            Utils::all($promises)->wait();
            unset($promises);

            $this->assertRecordedSpans($case, $transaction, $transaction === null ? 0 : 1 + $requests);
        }

        $this->record($case, 'bytes_per_pending_request', $runs, ['requests' => $requests]);
    }

    /**
     * Measure the time to create and join child coroutines inside a request with breadcrumbs.
     */
    private function measurePropagation(string $case, ?callable $prepare = null): void
    {
        $coroutines = $this->iterations(1000);
        $runs = [];
        RequestContext::set(Request::create('/orders/5?page=2', 'POST', ['name' => 'value'], [], [], ['HTTP_ACCEPT' => 'application/json']));

        for ($breadcrumb = 0; $breadcrumb < 20; ++$breadcrumb) {
            \Sentry\addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'bench', "breadcrumb {$breadcrumb}"));
        }

        for ($run = 0; $run < $this->runs(); ++$run) {
            $prepare && $prepare();
            $start = hrtime(true);
            $ids = [];

            for ($coroutine = 0; $coroutine < $coroutines; ++$coroutine) {
                $ids[] = Coroutine::create(static function (): void {
                });
            }

            SwooleCoroutine::join($ids);
            $runs[] = (hrtime(true) - $start) / 1000 / $coroutines;
        }

        // Only an active Sentry copies the parent's scope stack, with its breadcrumbs, into each child.
        $inherited = new Channel(1);
        Coroutine::create(function () use ($inherited): void {
            $copied = CoroutineContext::has(Hub::CONTEXT_STACK_KEY);
            $inherited->push([$copied, $copied ? count($this->getCurrentSentryBreadcrumbs()) : 0]);
        });
        $this->assertSame($prepare === null ? [false, 0] : [true, 20], $inherited->pop(1.0), $case);

        // Each hook runs for every child, so a duplicate registration would inflate the result.
        $hooks = count((static fn (): array => static::$afterCreatedCallbacks)->bindTo(null, Coroutine::class)());
        $this->assertSame($prepare === null ? 0 : 1, $hooks, "{$case}: afterCreated hooks");

        $this->record($case, 'us_per_coroutine', $runs, [
            'coroutines' => $coroutines,
            'breadcrumbs' => 20,
            'after_created_callbacks' => $hooks,
        ]);
    }

    /**
     * Start a transaction that is not sampled.
     */
    private function startUnsampledTransaction(): Transaction
    {
        $transaction = $this->getSentryHubFromContainer()->startTransaction(new TransactionContext);
        $transaction->setSampled(false);
        $this->getCurrentSentryScope()->setSpan($transaction);

        return $transaction;
    }

    /**
     * Skip cases that need the HTTP client origin options.
     */
    private function requireOriginOptions(): void
    {
        if (! array_key_exists('http_client_requests_origin', $this->shippedConfig()['tracing'])) {
            $this->markTestSkipped('This revision has no HTTP client origin options.');
        }
    }

    /**
     * Return config overrides with complete tracing and breadcrumb sections, since package config merges
     * only top-level keys and both revisions must see the same settings.
     */
    private function sentryConfig(array $options, array $tracing = [], array $breadcrumbs = []): array
    {
        $shipped = $this->shippedConfig();

        return $options + [
            'sentry.tracing' => array_replace($shipped['tracing'], $tracing),
            'sentry.breadcrumbs' => array_replace($shipped['breadcrumbs'], $breadcrumbs),
        ];
    }

    /**
     * Load this revision's shipped Sentry config.
     */
    private function shippedConfig(): array
    {
        return require dirname((new ReflectionClass(SentryServiceProvider::class))->getFileName()) . '/../config/sentry.php';
    }

    /**
     * Create a Guzzle client answered by a mock handler.
     */
    private function client(int $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, $responses, new Response(200, [], 'ok'))))]);
    }

    /**
     * Return the iteration count, reduced for smoke runs.
     */
    private function iterations(int $count): int
    {
        return getenv('BENCH_SMOKE') === '1' ? 20 : $count;
    }

    /**
     * Return the number of runs per case.
     */
    private function runs(): int
    {
        return getenv('BENCH_SMOKE') === '1' ? 1 : 3;
    }

    /**
     * Append one case's runs to the results file.
     */
    private function record(string $case, string $unit, array $runs, array $parameters): void
    {
        $sorted = $runs;
        sort($sorted);

        file_put_contents((string) getenv('BENCH_OUT'), json_encode([
            'case' => $case,
            'unit' => $unit,
            'median' => getenv('BENCH_SMOKE') === '1' ? null : $sorted[intdiv(count($sorted), 2)],
            'runs' => getenv('BENCH_SMOKE') === '1' ? [] : $runs,
            'parameters' => $parameters,
            'smoke' => getenv('BENCH_SMOKE') === '1',
        ]) . PHP_EOL, FILE_APPEND);
    }
}
