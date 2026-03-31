<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Aspects;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect;
use Hypervel\Tests\Sentry\SentryTestCase;
use Psr\Http\Message\RequestInterface;
use Sentry\Tracing\SpanStatus;

/**
 * @internal
 * @coversNothing
 */
class GuzzleHttpClientAspectTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testBreadcrumbIsRecorded()
    {
        $this->processAspect(
            new Request('GET', 'https://example.com/api/test'),
            new Response(200, [], 'OK')
        );

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
            'sentry.breadcrumbs.http_client_requests' => false,
        ]);

        $this->processAspect(
            new Request('GET', 'https://example.com'),
            new Response(200, [], 'OK')
        );

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testBreadcrumbLevelReflectsHttpStatus()
    {
        $this->processAspect(
            new Request('GET', 'https://example.com/ok'),
            new Response(200, [], 'OK')
        );
        $this->assertEquals('info', $this->getLastSentryBreadcrumb()->getLevel());

        $this->processAspect(
            new Request('GET', 'https://example.com/not-found'),
            new Response(404, [], 'Not Found')
        );
        $this->assertEquals('warning', $this->getLastSentryBreadcrumb()->getLevel());

        $this->processAspect(
            new Request('GET', 'https://example.com/error'),
            new Response(500, [], 'Internal Server Error')
        );
        $this->assertEquals('error', $this->getLastSentryBreadcrumb()->getLevel());
    }

    public function testSpanIsRecorded()
    {
        $transaction = $this->startTransaction();

        $this->processAspect(
            new Request('GET', 'https://example.com'),
            new Response(200, [], 'OK')
        );

        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals('http.client', $span->getOp());
        $this->assertEquals('GET https://example.com', $span->getDescription());
        $this->assertEquals('auto.http.guzzle', $span->getOrigin());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());
    }

    public function testSpanIsRecordedWithCorrectStatus()
    {
        $transaction = $this->startTransaction();

        $this->processAspect(
            new Request('GET', 'https://example.com/success'),
            new Response(200, [], 'OK')
        );

        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());

        $this->processAspect(
            new Request('GET', 'https://example.com/error'),
            new Response(500, [], 'Internal Server Error')
        );

        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
    }

    public function testSpanIsNotRecordedWhenDisabled()
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.http_client_requests' => false,
        ]);

        $transaction = $this->startTransaction();

        $this->processAspect(
            new Request('GET', 'https://example.com'),
            new Response(200, [], 'OK')
        );

        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertNotEquals('http.client', $span->getOp());
    }

    public function testTracingHeadersAreAttached()
    {
        $this->resetApplicationWithConfig([
            'sentry.trace_propagation_targets' => ['example.com'],
        ]);

        $sentRequest = null;

        $this->processAspect(
            new Request('GET', 'https://example.com'),
            new Response(200, [], 'OK'),
            capturedRequest: $sentRequest
        );

        $this->assertTrue($sentRequest->hasHeader('sentry-trace'));
        $this->assertTrue($sentRequest->hasHeader('baggage'));

        $sentRequest = null;

        $this->processAspect(
            new Request('GET', 'https://no-headers.example.com'),
            new Response(200, [], 'OK'),
            capturedRequest: $sentRequest
        );

        $this->assertFalse($sentRequest->hasHeader('sentry-trace'));
        $this->assertFalse($sentRequest->hasHeader('baggage'));
    }

    public function testPerRequestOptOut()
    {
        $this->processAspect(
            new Request('GET', 'https://example.com'),
            new Response(200, [], 'OK'),
            options: ['no_sentry_aspect' => true]
        );

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testPerClientOptOut()
    {
        $client = new Client([
            'handler' => HandlerStack::create(),
            'no_sentry_aspect' => true,
        ]);

        $this->processAspect(
            new Request('GET', 'https://example.com'),
            new Response(200, [], 'OK'),
            client: $client
        );

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testExistingOnStatsCallbackIsPreserved()
    {
        $callbackFired = false;

        $this->processAspect(
            new Request('GET', 'https://example.com'),
            new Response(200, [], 'OK'),
            options: [
                'on_stats' => function (TransferStats $stats) use (&$callbackFired) {
                    $callbackFired = true;
                },
            ]
        );

        $this->assertTrue($callbackFired, 'Existing on_stats callback should be preserved');
        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());
    }

    /**
     * Execute the aspect with the given request and response.
     *
     * Constructs a ProceedingJoinPoint that simulates GuzzleHttp\Client::transfer(),
     * then passes it through the aspect's process() method. The simulated transfer
     * fires the on_stats callback (which the aspect injects) with the response data.
     */
    private function processAspect(
        RequestInterface $request,
        Response $response,
        array $options = [],
        ?Client $client = null,
        ?RequestInterface &$capturedRequest = null,
    ): void {
        $aspect = $this->app->make(GuzzleHttpClientAspect::class);

        // The original method simulates Client::transfer() — it receives
        // the (potentially modified) request and options, fires on_stats,
        // and returns a fulfilled promise.
        $originalMethod = function (RequestInterface $request, array $options) use ($response, &$capturedRequest): FulfilledPromise {
            $capturedRequest = $request;

            if (isset($options['on_stats'])) {
                ($options['on_stats'])(new TransferStats($request, $response, 0.05));
            }

            return new FulfilledPromise($response);
        };

        // Bind the closure to a Client instance so getInstance() returns it
        // (used by the aspect's isOptedOut() for per-client config checks).
        if ($client !== null) {
            $originalMethod = $originalMethod->bindTo($client, Client::class);
        }

        $joinPoint = new ProceedingJoinPoint(
            $originalMethod,
            Client::class,
            'transfer',
            [
                'keys' => ['request' => $request, 'options' => $options],
                'order' => ['request', 'options'],
            ]
        );

        // Set the pipe to simulate being at the end of the aspect pipeline.
        // When the aspect calls $joinPoint->process(), this runs processOriginalMethod().
        $joinPoint->pipe = fn (ProceedingJoinPoint $point) => $point->processOriginalMethod();

        $aspect->process($joinPoint);
    }
}
