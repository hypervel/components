<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Aspects;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAop;
use Hypervel\Tests\Sentry\SentryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;

class GuzzleHttpClientAspectTest extends SentryTestCase
{
    use InteractsWithAop;

    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testBreadcrumbIsRecorded(): void
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

    public function testBreadcrumbIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.http_client_requests' => false,
        ]);

        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testBreadcrumbLevelReflectsHttpStatus(): void
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

    public function testSpanIsRecorded(): void
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

    public function testSpanIsRecordedWithCorrectStatus(): void
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

    public function testSpanIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.http_client_requests' => false,
        ]);

        $transaction = $this->startTransaction();

        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $span = last($transaction->getSpanRecorder()->getSpans());
        $this->assertNotEquals('http.client', $span->getOp());
    }

    public function testTracingHeadersAreAttached(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.trace_propagation_targets' => ['example.com'],
        ]);

        $mock = new MockHandler([
            new Response(200, [], 'OK'),
            new Response(200, [], 'OK'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $transaction = $this->startTransaction();

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));
        $sentRequest = $mock->getLastRequest();
        $this->assertTrue($sentRequest->hasHeader('sentry-trace'));
        $this->assertTrue($sentRequest->hasHeader('baggage'));
        $this->assertSame(
            $this->lastRecordedSpan($transaction)->toTraceparent(),
            $sentRequest->getHeaderLine('sentry-trace')
        );

        $this->executeTransfer($client, new Request('GET', 'https://no-headers.example.com'));
        $sentRequest = $mock->getLastRequest();
        $this->assertFalse($sentRequest->hasHeader('sentry-trace'));
        $this->assertFalse($sentRequest->hasHeader('baggage'));
    }

    public function testPerRequestOptOut(): void
    {
        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'), ['no_sentry_aspect' => true]);

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testPerClientOptOut(): void
    {
        $mock = new MockHandler([new Response(200, [], 'OK')]);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'no_sentry_aspect' => true,
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testExistingOnStatsCallbackIsPreserved(): void
    {
        $callbackFired = false;

        $client = $this->makeClient([
            new Response(200, [], 'OK'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'), [
            'on_stats' => function (TransferStats $stats) use (&$callbackFired): void {
                $callbackFired = true;
            },
        ]);

        $this->assertTrue($callbackFired, 'Existing on_stats callback should be preserved');
        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());
    }

    public function testConcurrentAsyncRequestsRemainSiblingSpansWithStableParentContext(): void
    {
        $underlyingPromises = [new Promise, new Promise];
        $requests = [];
        $requestOptions = [];
        $handler = function (RequestInterface $request, array $options) use ($underlyingPromises, &$requests, &$requestOptions): PromiseInterface {
            $index = count($requests);
            $requests[$index] = $request;
            $requestOptions[$index] = $options;

            return $underlyingPromises[$index];
        };
        $client = new Client(['handler' => $handler]);
        $transaction = $this->startTransaction();

        $firstPromise = $this->executeTransferAsync($client, new Request('GET', 'https://example.com/first'));
        $firstSpan = $this->lastRecordedSpan($transaction);
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());

        $secondPromise = $this->executeTransferAsync($client, new Request('GET', 'https://example.com/second'));
        $secondSpan = $this->lastRecordedSpan($transaction);

        $this->assertSame((string) $transaction->getSpanId(), (string) $firstSpan->getParentSpanId());
        $this->assertSame((string) $transaction->getSpanId(), (string) $secondSpan->getParentSpanId());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());

        $response = new Response(200, [], 'OK');
        ($requestOptions[1]['on_stats'])(new TransferStats($requests[1], $response, 0.01));
        $underlyingPromises[1]->resolve($response);

        $this->assertSame($response, $secondPromise->wait());
        $this->assertNotNull($secondSpan->getEndTimestamp());
        $this->assertNull($firstSpan->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());

        $firstPromise->cancel();
        Utils::queue()->run();

        $this->assertNotNull($firstSpan->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    #[DataProvider('cancellationPromiseProvider')]
    public function testAsyncCancellationFinishesOnceAndPropagatesToTheUnderlyingPromise(bool $cancelDownstream): void
    {
        $cancelCount = 0;
        $underlyingPromise = new Promise(null, function () use (&$cancelCount): void {
            ++$cancelCount;
        });
        $client = new Client([
            'handler' => static fn (): PromiseInterface => $underlyingPromise,
        ]);
        $transaction = $this->startTransaction();
        $promise = $this->executeTransferAsync($client, new Request('GET', 'https://example.com/cancel'));
        $span = $this->lastRecordedSpan($transaction);
        $promiseToCancel = $cancelDownstream
            ? $promise->then(static fn (mixed $value): mixed => $value)
            : $promise;

        $this->assertNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());

        $promiseToCancel->cancel();
        $endTimestamp = $span->getEndTimestamp();

        $this->assertSame(1, $cancelCount);
        $this->assertNotNull($endTimestamp);
        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());

        Utils::queue()->run();

        $this->assertSame($endTimestamp, $span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testPendingRejectionWithoutStatsFinishesSpanAndPreservesReason(): void
    {
        $underlyingPromise = new Promise;
        $client = new Client([
            'handler' => static fn (): PromiseInterface => $underlyingPromise,
        ]);
        $transaction = $this->startTransaction();
        $promise = $this->executeTransferAsync($client, new Request('GET', 'https://example.com/reject'));
        $span = $this->lastRecordedSpan($transaction);
        $reason = new RuntimeException('Rejected without transfer stats.');

        $underlyingPromise->reject($reason);

        try {
            $promise->wait();
            $this->fail('The rejected promise did not throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reason, $exception);
        }

        $this->assertNotNull($span->getEndTimestamp());
        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testAlreadyRejectedPromiseRetainsItsCancellationSemantics(): void
    {
        $reason = new RuntimeException('Already rejected.');
        $client = new Client([
            'handler' => static fn (): PromiseInterface => new RejectedPromise($reason),
        ]);
        $transaction = $this->startTransaction();
        $promise = $this->executeTransferAsync($client, new Request('GET', 'https://example.com/rejected'));
        $span = $this->lastRecordedSpan($transaction);

        $promise->cancel();

        try {
            $promise->wait();
            $this->fail('The rejected promise did not throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reason, $exception);
        }

        $this->assertNotNull($span->getEndTimestamp());
        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testStatsFinalizationIsNotRepeatedByLaterRejection(): void
    {
        $reason = new RuntimeException('Rejected after transfer stats.');
        $client = new Client([
            'handler' => static function (RequestInterface $request, array $options) use ($reason): PromiseInterface {
                ($options['on_stats'])(new TransferStats($request, new Response(200), 0.01));

                return new RejectedPromise($reason);
            },
        ]);
        $transaction = $this->startTransaction();
        $promise = $this->executeTransferAsync($client, new Request('GET', 'https://example.com/stats-reject'));
        $span = $this->lastRecordedSpan($transaction);
        $endTimestamp = $span->getEndTimestamp();

        try {
            $promise->wait();
            $this->fail('The rejected promise did not throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reason, $exception);
        }

        $this->assertNotNull($endTimestamp);
        $this->assertSame($endTimestamp, $span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testStatsFinalizationIsNotRepeatedByLaterCancellation(): void
    {
        $underlyingPromise = new Promise;
        $client = new Client([
            'handler' => static function (RequestInterface $request, array $options) use ($underlyingPromise): PromiseInterface {
                ($options['on_stats'])(new TransferStats($request, new Response(200), 0.01));

                return $underlyingPromise;
            },
        ]);
        $transaction = $this->startTransaction();
        $promise = $this->executeTransferAsync($client, new Request('GET', 'https://example.com/stats-cancel'));
        $span = $this->lastRecordedSpan($transaction);
        $endTimestamp = $span->getEndTimestamp();

        $promise->cancel();
        Utils::queue()->run();

        $this->assertNotNull($endTimestamp);
        $this->assertSame($endTimestamp, $span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public static function cancellationPromiseProvider(): array
    {
        return [
            'direct promise' => [false],
            'downstream promise' => [true],
        ];
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

    private function executeTransferAsync(Client $client, RequestInterface $request, array $options = []): PromiseInterface
    {
        if ($this->isAopProxied($client)) {
            return $client->sendAsync($request, $options);
        }

        ['request' => $preparedRequest, 'options' => $preparedOptions] = $this->prepareTransferArguments(
            $client,
            $request,
            $options
        );

        return $this->callWithAspects($client, 'transfer', [
            'request' => $preparedRequest,
            'options' => $preparedOptions,
        ]);
    }

    private function lastRecordedSpan(Transaction $transaction): Span
    {
        return last($transaction->getSpanRecorder()->getSpans());
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
