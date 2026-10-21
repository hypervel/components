<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Sentry\TestCase;
use Sentry\Tracing\Span;

class HttpClientDataCollectionTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testSpanUrlHeadersAndCookiesAreFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $data = $this->executeRequestAndReturnSpan()->getData();

        $this->assertSame('https://example.com/path', $data['url']);
        $this->assertSame('https://[Filtered]:[Filtered]@example.com/path?token=[Filtered]&page=5', $data['url.full']);
        $this->assertSame('token=[Filtered]&page=5', $data['http.query']);

        $this->assertSame('[Filtered]', $data['http.request.header.authorization']);
        $this->assertSame('bar', $data['http.request.header.x-request-id']);
        $this->assertSame(['theme=dark', 'session_id=[Filtered]'], $data['http.request.header.cookie']);

        $this->assertSame('baz', $data['http.response.header.x-request-id']);
        $this->assertSame('[Filtered]', $data['http.response.header.x-api-key']);
        $this->assertSame('web-1, web-2', $data['http.response.header.x-served-by']);
        $this->assertSame(['theme=light', 'session_id=[Filtered]'], $data['http.response.header.set-cookie']);
    }

    public function testBreadcrumbUrlIsFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $this->executeRequest();

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertSame('https://example.com/path', $metadata['url']);
        $this->assertSame('https://[Filtered]:[Filtered]@example.com/path?token=[Filtered]&page=5', $metadata['url.full']);
        $this->assertSame('token=[Filtered]&page=5', $metadata['http.query']);
        $this->assertSame([], $this->getHeaderKeys($metadata));
    }

    public function testSpanDataIsNotCollectedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'cookies' => ['mode' => 'off'],
                'http_headers' => ['mode' => 'off'],
                'url_query_params' => ['mode' => 'off'],
            ],
        ]);

        $data = $this->executeRequestAndReturnSpan()->getData();

        $this->assertSame('https://[Filtered]:[Filtered]@example.com/path', $data['url.full']);
        $this->assertArrayNotHasKey('http.query', $data);
        $this->assertSame([], $this->getHeaderKeys($data));
    }

    public function testLegacySpanAndBreadcrumbDataIsNotChanged(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
        ]);

        $data = $this->executeRequestAndReturnSpan()->getData();

        $this->assertSame('https://example.com/path', $data['url']);
        $this->assertSame('token=secret&page=5', $data['http.query']);
        $this->assertArrayNotHasKey('url.full', $data);
        $this->assertSame([], $this->getHeaderKeys($data));

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertSame('token=secret&page=5', $metadata['http.query']);
        $this->assertArrayNotHasKey('url.full', $metadata);
    }

    public function testLegacyDataKeepsAnEmptyQueryString(): void
    {
        $transaction = $this->startTransaction();

        Http::fake()->get('https://example.com/path');

        /** @var Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertSame('', $span->getData()['http.query']);
        $this->assertSame('', $this->getLastSentryBreadcrumb()->getMetadata()['http.query']);
    }

    public function testSpanJsonBodiesAreFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $data = $this->executeCallbackAndReturnSpan(static function (): void {
            Http::fake([
                'example.com/*' => Http::response(['token' => 'secret', 'id' => 1]),
            ])->post('https://example.com/login', ['username' => 'jane', 'password' => 'secret']);
        })->getData();

        $this->assertSame('{"username":"jane","password":"[Filtered]"}', $data['http.request.body.data']);
        $this->assertSame('{"token":"[Filtered]","id":1}', $data['http.response.body.data']);
    }

    public function testSpanFormBodyIsFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $data = $this->executeCallbackAndReturnSpan(static function (): void {
            Http::fake()->asForm()->post('https://example.com/login', ['username' => 'jane', 'password' => 'secret']);
        })->getData();

        $this->assertSame('{"username":"jane","password":"[Filtered]"}', $data['http.request.body.data']);
    }

    public function testSpanRawBodiesAreReplaced(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $data = $this->executeCallbackAndReturnSpan(static function (): void {
            Http::fake([
                'example.com/*' => Http::response('Hello World', 200, ['Content-Type' => 'text/plain']),
            ])->withBody('Hello World', 'text/plain')->post('https://example.com/');
        })->getData();

        $this->assertSame('[Filtered]', $data['http.request.body.data']);
        $this->assertSame('[Filtered]', $data['http.response.body.data']);
    }

    public function testSpanBodiesAreNotConsumed(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $response = null;

        $data = $this->executeCallbackAndReturnSpan(static function () use (&$response): void {
            $response = Http::fake([
                'example.com/*' => Http::response('{"id":1}', 200, ['Content-Type' => 'application/json']),
            ])->withBody('{"username":"jane"}', 'application/json')->post('https://example.com/');
        })->getData();

        $this->assertSame('{"username":"jane"}', $data['http.request.body.data']);
        $this->assertSame('{"id":1}', $data['http.response.body.data']);
        Http::assertSent(static fn (Request $request): bool => $request->toPsrRequest()->getBody()->getContents() === '{"username":"jane"}');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('{"id":1}', $response->toPsrResponse()->getBody()->getContents());
    }

    public function testSpanRequestBodyRespectsMaxRequestBodySize(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.max_request_body_size' => 'none',
            'sentry.data_collection' => [],
        ]);

        $data = $this->executeCallbackAndReturnSpan(static function (): void {
            Http::fake([
                'example.com/*' => Http::response(['id' => 1]),
            ])->post('https://example.com/', ['username' => 'jane']);
        })->getData();

        $this->assertArrayNotHasKey('http.request.body.data', $data);
        $this->assertSame('{"id":1}', $data['http.response.body.data']);
    }

    public function testSpanBodiesAreNotCollectedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'http_bodies' => ['incomingRequest', 'outgoingResponse'],
            ],
        ]);

        $data = $this->executeCallbackAndReturnSpan(static function (): void {
            Http::fake([
                'example.com/*' => Http::response(['id' => 1]),
            ])->post('https://example.com/', ['username' => 'jane']);
        })->getData();

        $this->assertArrayNotHasKey('http.request.body.data', $data);
        $this->assertArrayNotHasKey('http.response.body.data', $data);
    }

    public function testLegacySpanHasNoBodies(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
        ]);

        $data = $this->executeCallbackAndReturnSpan(static function (): void {
            Http::fake([
                'example.com/*' => Http::response(['id' => 1]),
            ])->post('https://example.com/', ['username' => 'jane']);
        })->getData();

        $this->assertArrayNotHasKey('http.request.body.data', $data);
        $this->assertArrayNotHasKey('http.response.body.data', $data);
    }

    /**
     * Run the callback within a transaction and return its HTTP client span.
     */
    private function executeCallbackAndReturnSpan(callable $callback): Span
    {
        $transaction = $this->startTransaction();

        $callback();

        /** @var Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertSame('http.client', $span->getOp());

        return $span;
    }

    /**
     * Send the shared request within a transaction and return its HTTP client span.
     */
    private function executeRequestAndReturnSpan(): Span
    {
        return $this->executeCallbackAndReturnSpan(function (): void {
            $this->executeRequest();
        });
    }

    /**
     * Send a request with sensitive URL, header and cookie values.
     */
    private function executeRequest(): void
    {
        $client = Http::fake([
            'example.com/*' => Http::response('', 200, [
                'X-Request-Id' => 'baz',
                'X-Api-Key' => 'secret',
                'X-Served-By' => ['web-1', 'web-2'],
                'Set-Cookie' => ['theme=light; Path=/', 'session_id=foo; HttpOnly'],
            ]),
        ]);

        $client->withHeaders([
            'Authorization' => 'Bearer foo',
            'X-Request-Id' => 'bar',
            'Cookie' => 'theme=dark; session_id=foo',
        ])->get('https://user:pass@example.com/path?token=secret&page=5');
    }

    /**
     * Get the collected header keys.
     *
     * @return string[]
     */
    private function getHeaderKeys(array $data): array
    {
        return array_values(array_filter(array_keys($data), static function (string $key): bool {
            return str_starts_with($key, 'http.request.header.') || str_starts_with($key, 'http.response.header.');
        }));
    }
}
