<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Sentry\Fixtures\HttpClientOriginFixture;
use Hypervel\Tests\Sentry\TestCase;
use ReflectionMethod;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

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
