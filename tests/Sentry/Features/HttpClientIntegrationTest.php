<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Request;
use Hypervel\Sentry\Features\Storage\Integration as StorageIntegration;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Sentry\Fixtures\HttpClientOriginFixture;
use Hypervel\Tests\Sentry\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

use function Hypervel\Coroutine\parallel;

class HttpClientIntegrationTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testHttpClientBreadcrumbIsRecordedForResponseReceivedEvent(): void
    {
        Http::fake(['example.com' => Http::response('response')]);

        Http::withBody('request', 'text/plain')->get('https://example.com');

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertEquals('GET', $metadata['http.request.method']);
        $this->assertEquals('https://example.com', $metadata['url']);
        $this->assertEquals(200, $metadata['http.response.status_code']);
        $this->assertEquals(7, $metadata['http.request.body.size']);
        $this->assertEquals(8, $metadata['http.response.body.size']);
    }

    public function testHttpClientBreadcrumbDoesntConsumeBodyStream(): void
    {
        Http::fake(['example.com' => Http::response('response')]);

        $response = Http::withBody('request', 'text/plain')->get('https://example.com');

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());

        Http::assertSent(static fn (Request $request): bool => $request->toPsrRequest()->getBody()->getContents() === 'request');
        $this->assertEquals('response', $response->toPsrResponse()->getBody()->getContents());
    }

    public function testHttpClientBreadcrumbIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.http_client_requests' => false,
        ]);

        Http::fake(['example.com' => Http::response('response')]);

        Http::withBody('request', 'text/plain')->get('https://example.com');

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testHttpClientSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();

        $client = Http::fake();

        $client->get('https://example.com');

        /** @var Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals('http.client', $span->getOp());
        $this->assertEquals('GET https://example.com', $span->getDescription());
        $this->assertEquals('auto.http.guzzle', $span->getOrigin());
    }

    public function testHttpClientSpanIsRecordedWithCorrectResult(): void
    {
        $transaction = $this->startTransaction();

        $client = Http::fake([
            'example.com/success' => Http::response('OK'),
            'example.com/error' => Http::response('Internal Server Error', 500),
        ]);

        $client->get('https://example.com/success');

        /** @var Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals('http.client', $span->getOp());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());

        $client->get('https://example.com/error');

        /** @var Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals('http.client', $span->getOp());
        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
    }

    public function testHttpClientSpanIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.http_client_requests' => false,
        ]);

        $transaction = $this->startTransaction();

        $client = Http::fake();

        $client->get('https://example.com');

        /** @var Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertNotEquals('http.client', $span->getOp());
    }

    public function testHttpClientRequestTracingHeadersAreAttached(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.trace_propagation_targets' => ['example.com'],
        ]);

        $client = Http::fake();

        $client->get('https://example.com');

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('baggage') && $request->hasHeader('sentry-trace');
        });

        $client->get('https://no-headers.example.com');

        Http::assertSent(function (Request $request): bool {
            return ! $request->hasHeader('baggage') && ! $request->hasHeader('sentry-trace');
        });
    }

    public function testHttpClientOriginIsResolvedWhenEnabled(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => true,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 0,
        ]);

        $transaction = $this->startTransaction();

        Http::fake();

        (new HttpClientOriginFixture)->get('https://example.com');

        $this->assertSpanHasFixtureOrigin(last($transaction->getSpanRecorder()->getSpans()));
    }

    public function testHttpClientOriginIsNotResolvedWhenDisabled(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => false,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 0,
        ]);

        $transaction = $this->startTransaction();

        Http::fake();

        (new HttpClientOriginFixture)->get('https://example.com');

        $this->assertSpanHasNoOrigin(last($transaction->getSpanRecorder()->getSpans()));
    }

    public function testHttpClientOriginIsResolvedWhenOverThreshold(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => true,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 10,
        ]);

        $transaction = $this->startTransaction();

        Http::fake([
            'slow.example.com' => function (): PromiseInterface {
                usleep(20000); // 20ms delay
                return Http::response('OK');
            },
        ]);

        (new HttpClientOriginFixture)->get('https://slow.example.com');

        $this->assertSpanHasFixtureOrigin(last($transaction->getSpanRecorder()->getSpans()));
    }

    public function testHttpClientOriginIsNotResolvedWhenUnderThreshold(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => true,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 1000,
        ]);

        $transaction = $this->startTransaction();

        Http::fake();

        (new HttpClientOriginFixture)->get('https://example.com');

        $this->assertSpanHasNoOrigin(last($transaction->getSpanRecorder()->getSpans()));
    }

    public function testHttpClientOriginIsResolvedWhenConnectionFailed(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => true,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 0,
        ]);

        $transaction = $this->startTransaction();

        Http::fake(function (): never {
            throw new ConnectException('Connection timed out', new PsrRequest('GET', 'https://example.com'));
        });

        try {
            (new HttpClientOriginFixture)->get('https://example.com');

            $this->fail('Expected the HTTP client request to fail.');
        } catch (ConnectionException $e) {
            // We only care about the span that was recorded for the failed request
        }

        /** @var Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        $this->assertSpanHasFixtureOrigin($span);
    }

    // REMOVED: testHttpClientOriginThresholdAcceptsNumericStrings - the config file casts the threshold to an integer, like sql_origin_threshold_ms
    // REMOVED: testHttpClientOriginThresholdFallsBackToDefaultWhenNotNumeric - the config file casts the threshold to an integer, like sql_origin_threshold_ms

    public function testHttpClientOriginOfAnAsyncRequestIsWhereTheRequestStarted(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => true,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 0,
        ]);

        $transaction = $this->startTransaction();

        Http::fake();

        $promise = (new HttpClientOriginFixture)->getAsync('https://example.com');
        $promise->wait();

        $this->assertSpanHasFixtureOrigin(last($transaction->getSpanRecorder()->getSpans()), 'getAsync');
    }

    public function testBreadcrumbLevelReflectsHttpStatus(): void
    {
        $client = $this->makeClient([
            new Response(200, [], 'OK'),
            new Response(404, [], 'Not Found'),
            new Response(500, [], 'Internal Server Error'),
        ], ['http_errors' => false]);

        $client->send(new PsrRequest('GET', 'https://example.com/ok'), ['http_errors' => false]);
        $this->assertEquals('info', $this->getLastSentryBreadcrumb()->getLevel());

        $client->send(new PsrRequest('GET', 'https://example.com/not-found'), ['http_errors' => false]);
        $this->assertEquals('warning', $this->getLastSentryBreadcrumb()->getLevel());

        $client->send(new PsrRequest('GET', 'https://example.com/error'), ['http_errors' => false]);
        $this->assertEquals('error', $this->getLastSentryBreadcrumb()->getLevel());
    }

    public function testErrorResponsesRejectedByGuzzleKeepTheirStatus(): void
    {
        $transaction = $this->startTransaction();
        $client = $this->makeClient([new Response(404, [], 'Not Found')]);

        try {
            $client->send(new PsrRequest('GET', 'https://example.com/missing'));
            $this->fail('Expected Guzzle to reject the error response.');
        } catch (ClientException) {
        }

        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertSame(SpanStatus::notFound(), $span->getStatus());
        $this->assertSame(404, $span->getData()['http.response.status_code']);
        $this->assertSame('warning', $this->getLastSentryBreadcrumb()->getLevel());
        $this->assertSame(404, $this->getLastSentryBreadcrumb()->getMetadata()['http.response.status_code']);
    }

    public function testRedirectedRequestIsRecordedOnceWithTheFinalRequestAndResponse(): void
    {
        $transaction = $this->startTransaction();
        $hops = [];
        $client = $this->makeClient([
            new Response(302, ['Location' => 'https://example.com/final']),
            new Response(200, [], 'OK'),
        ]);

        $client->get('https://example.com/start', [
            'on_stats' => static function (TransferStats $stats) use (&$hops): void {
                $hops[] = (string) $stats->getRequest()->getUri();
            },
        ]);

        $this->assertSame(['https://example.com/start', 'https://example.com/final'], $hops);

        $spans = array_values(array_filter(
            $transaction->getSpanRecorder()->getSpans(),
            static fn (Span $span): bool => $span->getOp() === 'http.client'
        ));
        $this->assertCount(1, $spans);
        $this->assertSame('GET https://example.com/final', $spans[0]->getDescription());
        $this->assertSame(200, $spans[0]->getData()['http.response.status_code']);

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());
        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();
        $this->assertSame('https://example.com/final', $metadata['url']);
        $this->assertSame(200, $metadata['http.response.status_code']);
    }

    public function testRequestOptionsAppliedByGuzzleAreRecorded(): void
    {
        $transaction = $this->startTransaction();
        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $client->post('https://example.com/path', [
            'query' => ['page' => 5],
            'json' => ['name' => 'jane'],
        ]);

        $data = last($transaction->getSpanRecorder()->getSpans())->getData();
        $this->assertSame('page=5', $data['http.query']);
        $this->assertSame(15, $data['http.request.body.size']);
        $this->assertSame('page=5', $this->getLastSentryBreadcrumb()->getMetadata()['http.query']);
    }

    public function testDeferredAsyncRequestIsRecordedInTheCoroutineThatSentIt(): void
    {
        [$first, $second] = parallel([
            function (): array {
                $promise = $this->makeClient([new Response(200, [], 'OK')])->getAsync('https://example.com/first');
                usleep(5000);
                $promise->wait();

                return $this->breadcrumbUrls();
            },
            function (): array {
                usleep(1000);
                $this->makeClient([new Response(200, [], 'OK')])->get('https://example.com/second');

                return $this->breadcrumbUrls();
            },
        ]);

        $this->assertSame(['https://example.com/first'], $first);
        $this->assertSame(['https://example.com/second'], $second);
    }

    public function testOriginOfAnSdkRequestSentThroughGuzzleAsyncIsTheApplicationCaller(): void
    {
        // The AWS SDK sends synchronous calls through sendAsync(), so a call on a Sentry-decorated S3 disk
        // reaches Guzzle below the storage decorators and the SDK's middleware
        $this->resetApplicationWithConfig([
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 0,
            'sentry.in_app_exclude' => [dirname(__DIR__, 3) . '/src', dirname(__DIR__, 3) . '/vendor'],
            'filesystems.disks.s3' => StorageIntegration::configureDisk('s3', [
                'driver' => 's3',
                'key' => 'key',
                'secret' => 'secret',
                'region' => 'us-east-1',
                'bucket' => 'bucket',
                'http_handler' => new GuzzleHandler($this->makeClient([new Response(200)])),
                'pool' => ['fingerprint' => 'sentry-origin-mock-handler'],
            ]),
        ]);
        $transaction = $this->startTransaction();

        $line = __LINE__ + 1;
        $this->assertTrue(Storage::disk('s3')->exists('file.txt'));

        $spans = array_values(array_filter(
            $transaction->getSpanRecorder()->getSpans(),
            static fn (Span $span): bool => $span->getOp() === 'http.client'
        ));
        $this->assertCount(1, $spans);
        $data = $spans[0]->getData();
        $this->assertSame(__FILE__, $data['code.filepath'] ?? null);
        $this->assertSame($line, $data['code.lineno'] ?? null);
        $this->assertSame(__METHOD__, $data['code.function'] ?? null);
    }

    public function testHttpSpanNeverBecomesTheHubCurrentSpan(): void
    {
        $transaction = $this->startTransaction();
        $observedSpan = null;
        $client = $this->makeClient([
            static function () use (&$observedSpan): Response {
                $observedSpan = SentrySdk::getCurrentHub()->getSpan();

                return new Response(200, [], 'OK');
            },
        ]);

        $client->send(new PsrRequest('GET', 'https://example.com'));

        $this->assertSame($transaction, $observedSpan);
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testFailedTransferFinishesTheExactHttpSpan(): void
    {
        $transaction = $this->startTransaction();
        $exception = new RuntimeException('Connection failed.');
        $client = $this->makeClient([$exception]);

        try {
            $client->send(new PsrRequest('GET', 'https://example.com'));
            $this->fail('Expected the transfer to fail.');
        } catch (RuntimeException $thrown) {
            $this->assertSame($exception, $thrown);
        }

        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertSame('http.client', $span->getOp());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame(SpanStatus::internalError(), $span->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testNonThrowableRejectionReasonsArePreserved(): void
    {
        $transaction = $this->startTransaction();
        $client = $this->makeClient([
            static fn (): PromiseInterface => Create::rejectionFor('Connection failed.'),
        ]);

        $reason = null;
        $client->sendAsync(new PsrRequest('GET', 'https://example.com'))->then(
            null,
            static function (mixed $rejection) use (&$reason): void {
                $reason = $rejection;
            }
        )->wait();

        $this->assertSame('Connection failed.', $reason);
        $this->assertSame(SpanStatus::internalError(), last($transaction->getSpanRecorder()->getSpans())->getStatus());
    }

    public function testTracingHeadersAreAttachedWhenLocalRecordingIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.http_client_requests' => false,
            'sentry.breadcrumbs.http_client_requests' => false,
        ]);
        $mock = new MockHandler([new Response(200, [], 'OK')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $client->send(new PsrRequest('GET', 'https://example.com'));

        $sentRequest = $mock->getLastRequest();
        $this->assertTrue($sentRequest->hasHeader('sentry-trace'));
        $this->assertTrue($sentRequest->hasHeader('baggage'));
        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testTransferStatsCallbackIsNotWrappedWhenLocalOutputIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.http_client_requests' => false,
            'sentry.breadcrumbs.http_client_requests' => false,
        ]);
        $observedOnStats = null;
        $existingOnStats = static function (TransferStats $stats): void {
        };
        $client = $this->makeClient([
            static function (RequestInterface $request, array $options) use (&$observedOnStats): Response {
                $observedOnStats = $options['on_stats'] ?? null;

                return new Response(200, [], 'OK');
            },
        ]);

        $client->send(new PsrRequest('GET', 'https://example.com'), [
            'on_stats' => $existingOnStats,
        ]);

        $this->assertSame($existingOnStats, $observedOnStats);
    }

    public function testLegacyEnableTracingOptionRecordsSpansWithoutAnExplicitSampler(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.enable_tracing' => true,
            'sentry.traces_sample_rate' => null,
            'sentry.breadcrumbs.http_client_requests' => false,
        ]);
        $transaction = $this->startTransaction();
        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $client->send(new PsrRequest('GET', 'https://example.com'));

        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertSame('http.client', $span->getOp());
    }

    public function testPerRequestOptOut(): void
    {
        $mock = new MockHandler([new Response(200, [], 'OK')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $client->send(new PsrRequest('GET', 'https://example.com'), ['sentry_enabled' => false]);

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
        $this->assertFalse($mock->getLastRequest()->hasHeader('sentry-trace'));
        $this->assertFalse($mock->getLastRequest()->hasHeader('baggage'));
    }

    public function testPerClientOptOut(): void
    {
        $mock = new MockHandler([new Response(200, [], 'OK')]);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'sentry_enabled' => false,
        ]);

        $client->send(new PsrRequest('GET', 'https://example.com'));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
        $this->assertFalse($mock->getLastRequest()->hasHeader('sentry-trace'));
        $this->assertFalse($mock->getLastRequest()->hasHeader('baggage'));
    }

    /**
     * Get the URLs of the current coroutine's HTTP breadcrumbs.
     *
     * @return list<string>
     */
    private function breadcrumbUrls(): array
    {
        return array_values(array_map(
            static fn (Breadcrumb $breadcrumb): string => $breadcrumb->getMetadata()['url'],
            $this->getCurrentSentryBreadcrumbs()
        ));
    }

    /**
     * Create a Guzzle client with a MockHandler queuing the given responses.
     */
    private function makeClient(array $responses, array $config = []): Client
    {
        return new Client(array_merge($config, [
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }

    /**
     * Reload the application with origin settings and production-like in-app exclusions.
     */
    private function resetApplicationWithOriginConfig(array $config): void
    {
        $this->resetApplicationWithConfig(array_merge([
            // The service provider excludes `base_path('vendor')` from in-app frames, but our base path is the
            // Testbench skeleton, so exclude this repository's framework sources and vendor directory to match
            // a real application
            'sentry.in_app_exclude' => [dirname(__DIR__, 3) . '/src', dirname(__DIR__, 3) . '/vendor'],
        ], $config));
    }

    /**
     * Assert that the span's origin is the request made by the given fixture method.
     */
    private function assertSpanHasFixtureOrigin(Span $span, string $methodName = 'get'): void
    {
        $method = new ReflectionMethod(HttpClientOriginFixture::class, $methodName);

        $data = $span->getData();

        $this->assertSame($method->getFileName(), $data['code.filepath'] ?? null);
        // The request is made on the first line of the method body
        $this->assertSame($method->getStartLine() + 2, $data['code.lineno'] ?? null);
        $this->assertSame(HttpClientOriginFixture::class . '::' . $methodName, $data['code.function'] ?? null);
    }

    /**
     * Assert that the span has no origin.
     */
    private function assertSpanHasNoOrigin(Span $span): void
    {
        $this->assertArrayNotHasKey('code.filepath', $span->getData());
        $this->assertArrayNotHasKey('code.lineno', $span->getData());
        $this->assertArrayNotHasKey('code.function', $span->getData());
    }
}
