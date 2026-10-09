<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Aspects;

use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Hypervel\Sentry\Features\Storage\Integration as StorageIntegration;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Sentry\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

use function Hypervel\Coroutine\parallel;

class GuzzleHttpClientAspectTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testBreadcrumbLevelReflectsHttpStatus(): void
    {
        $client = $this->makeClient([
            new Response(200, [], 'OK'),
            new Response(404, [], 'Not Found'),
            new Response(500, [], 'Internal Server Error'),
        ], ['http_errors' => false]);

        $client->send(new Request('GET', 'https://example.com/ok'), ['http_errors' => false]);
        $this->assertEquals('info', $this->getLastSentryBreadcrumb()->getLevel());

        $client->send(new Request('GET', 'https://example.com/not-found'), ['http_errors' => false]);
        $this->assertEquals('warning', $this->getLastSentryBreadcrumb()->getLevel());

        $client->send(new Request('GET', 'https://example.com/error'), ['http_errors' => false]);
        $this->assertEquals('error', $this->getLastSentryBreadcrumb()->getLevel());
    }

    public function testErrorResponsesRejectedByGuzzleKeepTheirStatus(): void
    {
        $transaction = $this->startTransaction();
        $client = $this->makeClient([new Response(404, [], 'Not Found')]);

        try {
            $client->send(new Request('GET', 'https://example.com/missing'));
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

        $client->send(new Request('GET', 'https://example.com'));

        $this->assertSame($transaction, $observedSpan);
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testFailedTransferFinishesTheExactHttpSpan(): void
    {
        $transaction = $this->startTransaction();
        $exception = new RuntimeException('Connection failed.');
        $client = $this->makeClient([$exception]);

        try {
            $client->send(new Request('GET', 'https://example.com'));
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
        $client->sendAsync(new Request('GET', 'https://example.com'))->then(
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

        $client->send(new Request('GET', 'https://example.com'));

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

        $client->send(new Request('GET', 'https://example.com'), [
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

        $client->send(new Request('GET', 'https://example.com'));

        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertSame('http.client', $span->getOp());
    }

    public function testPerRequestOptOut(): void
    {
        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $client->send(new Request('GET', 'https://example.com'), ['no_sentry_aspect' => true]);

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testPerClientOptOut(): void
    {
        $mock = new MockHandler([new Response(200, [], 'OK')]);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'no_sentry_aspect' => true,
        ]);

        $client->send(new Request('GET', 'https://example.com'));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
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
     *
     * The shared PHPUnit extension generates GuzzleHttp\Client's proxy before
     * test discovery, so registered aspects intercept transfer() automatically.
     */
    private function makeClient(array $responses, array $config = []): Client
    {
        return new Client(array_merge($config, [
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }
}
