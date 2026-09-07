<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Aspects;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAop;
use Hypervel\Tests\Sentry\SentryTestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanStatus;

class GuzzleHttpClientAspectTest extends SentryTestCase
{
    use InteractsWithAop;

    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testBreadcrumbIsRecorded()
    {
        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com/api/test'));

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $metadata = $breadcrumb->getMetadata();

        $this->assertEquals('http', $breadcrumb->getType());
        $this->assertEquals('http', $breadcrumb->getCategory());
        $this->assertEquals('GET', $metadata['http.request.method']);
        $this->assertEquals('https://example.com/api/test', $metadata['url']);
        $this->assertEquals(200, $metadata['http.response.status_code']);
    }

    public function testBreadcrumbIsNotRecordedWhenDisabled()
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.http_client_requests' => false,
            ]),
        ]);

        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testBreadcrumbLevelReflectsHttpStatus()
    {
        $client = $this->makeClient([
            new Response(200, [], 'OK'),
            new Response(404, [], 'Not Found'),
            new Response(500, [], 'Internal Server Error'),
        ], ['http_errors' => false]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com/ok'), ['http_errors' => false]);
        $this->assertEquals('info', $this->getLastSentryBreadcrumb()->getLevel());

        $this->executeTransfer($client, new Request('GET', 'https://example.com/not-found'), ['http_errors' => false]);
        $this->assertEquals('warning', $this->getLastSentryBreadcrumb()->getLevel());

        $this->executeTransfer($client, new Request('GET', 'https://example.com/error'), ['http_errors' => false]);
        $this->assertEquals('error', $this->getLastSentryBreadcrumb()->getLevel());
    }

    public function testSpanIsRecorded()
    {
        $transaction = $this->startTransaction();

        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals('http.client', $span->getOp());
        $this->assertEquals('GET https://example.com', $span->getDescription());
        $this->assertEquals('auto.http.guzzle', $span->getOrigin());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());
    }

    public function testSpanIsRecordedWithCorrectStatus()
    {
        $transaction = $this->startTransaction();

        $client = $this->makeClient([
            new Response(200, [], 'OK'),
            new Response(500, [], 'Internal Server Error'),
        ], ['http_errors' => false]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com/success'), ['http_errors' => false]);
        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());

        $this->executeTransfer($client, new Request('GET', 'https://example.com/error'), ['http_errors' => false]);
        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
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

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $this->assertSame($transaction, $observedSpan);
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testFailedTransferFinishesTheExactHttpSpan(): void
    {
        $transaction = $this->startTransaction();
        $exception = new RuntimeException('Connection failed.');
        $client = $this->makeClient([$exception]);

        try {
            $this->executeTransfer($client, new Request('GET', 'https://example.com'));
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

    public function testSpanIsNotRecordedWhenDisabled()
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'traces_sample_rate' => 1.0,
                'tracing.http_client_requests' => false,
            ]),
        ]);

        $transaction = $this->startTransaction();

        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertNotEquals('http.client', $span->getOp());
    }

    public function testTracingHeadersAreAttached()
    {
        $this->resetApplicationWithConfig([
            'sentry.trace_propagation_targets' => ['example.com'],
        ]);

        $mock = new MockHandler([
            new Response(200, [], 'OK'),
            new Response(200, [], 'OK'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $this->startTransaction();

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));
        $sentRequest = $mock->getLastRequest();
        $this->assertTrue($sentRequest->hasHeader('sentry-trace'));
        $this->assertTrue($sentRequest->hasHeader('baggage'));

        $this->executeTransfer($client, new Request('GET', 'https://no-headers.example.com'));
        $sentRequest = $mock->getLastRequest();
        $this->assertFalse($sentRequest->hasHeader('sentry-trace'));
        $this->assertFalse($sentRequest->hasHeader('baggage'));
    }

    public function testTracingHeadersAreAttachedWhenLocalRecordingIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'tracing.http_client_requests' => false,
                'breadcrumbs.http_client_requests' => false,
            ]),
        ]);
        $mock = new MockHandler([new Response(200, [], 'OK')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $sentRequest = $mock->getLastRequest();
        $this->assertTrue($sentRequest->hasHeader('sentry-trace'));
        $this->assertTrue($sentRequest->hasHeader('baggage'));
        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testTransferStatsCallbackIsNotWrappedWhenLocalOutputIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'tracing.http_client_requests' => false,
                'breadcrumbs.http_client_requests' => false,
            ]),
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

        $this->executeTransfer($client, new Request('GET', 'https://example.com'), [
            'on_stats' => $existingOnStats,
        ]);

        $this->assertSame($existingOnStats, $observedOnStats);
    }

    public function testLegacyEnableTracingOptionRecordsSpansWithoutAnExplicitSampler(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'enable_tracing' => true,
                'traces_sample_rate' => null,
                'breadcrumbs.http_client_requests' => false,
            ]),
        ]);
        $transaction = $this->startTransaction();
        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertSame('http.client', $span->getOp());
    }

    public function testPerRequestOptOut()
    {
        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'), ['no_sentry_aspect' => true]);

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testPerClientOptOut()
    {
        $mock = new MockHandler([new Response(200, [], 'OK')]);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'no_sentry_aspect' => true,
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testExistingOnStatsCallbackIsPreserved()
    {
        $callbackFired = false;

        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'), [
            'on_stats' => function (TransferStats $stats) use (&$callbackFired) {
                $callbackFired = true;
            },
        ]);

        $this->assertTrue($callbackFired, 'Existing on_stats callback should be preserved');
        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());
    }

    /**
     * Create a Guzzle client with a MockHandler queuing the given responses.
     *
     * The AOP proxy for GuzzleHttp\Client is generated by Testbench's bootstrap
     * (GenerateProxies), so the aspect intercepts transfer() automatically.
     */
    private function makeClient(array $responses, array $config = []): Client
    {
        return new Client(array_merge($config, [
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }

    private function executeTransfer(Client $client, RequestInterface $request, array $options = []): void
    {
        if ($this->isAopProxied($client)) {
            $client->send($request, $options);

            return;
        }

        ['request' => $preparedRequest, 'options' => $preparedOptions] = $this->prepareTransferArguments(
            $client,
            $request,
            $options
        );

        $this->callWithAspects($client, 'transfer', [
            'request' => $preparedRequest,
            'options' => $preparedOptions,
        ])->wait();
    }

    /**
     * Mirror Guzzle's sendAsync() setup before manually invoking transfer().
     *
     * @return array{request: RequestInterface, options: array}
     */
    private function prepareTransferArguments(Client $client, RequestInterface $request, array $options): array
    {
        $preparedOptions = (fn (array $options): array => $this->prepareDefaults($options))
            ->call($client, $options);

        $preparedUri = (fn ($uri, array $options) => $this->buildUri($uri, $options))
            ->call($client, $request->getUri(), $preparedOptions);

        return [
            'request' => $request->withUri($preparedUri, $request->hasHeader('Host')),
            'options' => $preparedOptions,
        ];
    }
}
