<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Faking;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Saloon\Exceptions\NoMockResponseFoundException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\ReflectsClosures;
use PHPUnit\Framework\Assert as PHPUnit;
use ReflectionFunction;

class MockClient
{
    use ReflectsClosures;

    /**
     * Responses consumed in registration order.
     *
     * @var list<callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse>
     */
    protected array $sequenceResponses = [];

    /**
     * Responses keyed by connector class.
     *
     * @var array<class-string<Connector>, callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse>
     */
    protected array $connectorResponses = [];

    /**
     * Responses keyed by request class.
     *
     * @var array<class-string<Request>, callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse>
     */
    protected array $requestResponses = [];

    /**
     * Responses keyed by URL pattern.
     *
     * @var array<string, callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse>
     */
    protected array $urlResponses = [];

    /**
     * Recorded Saloon responses.
     *
     * @var list<Response>
     */
    protected array $recordedResponses = [];

    /**
     * Whether unmatched requests are prevented.
     */
    protected bool $preventStrayRequests = true;

    /**
     * URL patterns allowed to continue without a fake.
     *
     * A null value permits every unmatched request once stray requests are enabled.
     *
     * @var null|list<string>
     */
    protected ?array $allowedStrayRequestUrls = [];

    /**
     * Create a mock client.
     *
     * @param array<array-key, callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse> $mockData
     */
    public function __construct(array $mockData = [])
    {
        $this->addResponses($mockData);
    }

    /**
     * Add mock responses.
     *
     * @param array<array-key, callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse> $responses
     */
    public function addResponses(array $responses): void
    {
        foreach ($responses as $captureMethod => $response) {
            $this->addResponse($response, is_string($captureMethod) ? $captureMethod : null);
        }
    }

    /**
     * Add a mock response.
     *
     * @param callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse $response
     */
    public function addResponse(MockResponse|Fixture|callable $response, ?string $captureMethod = null): void
    {
        if ($captureMethod === null) {
            $this->sequenceResponses[] = $response;

            return;
        }

        if (is_a($captureMethod, Connector::class, true)) {
            $this->connectorResponses[$captureMethod] = $response;

            return;
        }

        if (is_a($captureMethod, Request::class, true)) {
            $this->requestResponses[$captureMethod] = $response;

            return;
        }

        $this->urlResponses[$captureMethod] = $response;
    }

    /**
     * Resolve the next matching response.
     */
    public function match(PendingRequest $pendingRequest): MockResponse|Fixture|null
    {
        $requestClass = $pendingRequest->request()::class;

        if (array_key_exists($requestClass, $this->requestResponses)) {
            return $this->resolveValue($this->requestResponses[$requestClass], $pendingRequest);
        }

        $connectorClass = $pendingRequest->connector()::class;

        if (array_key_exists($connectorClass, $this->connectorResponses)) {
            return $this->resolveValue($this->connectorResponses[$connectorClass], $pendingRequest);
        }

        foreach ($this->urlResponses as $url => $response) {
            if (Str::is($url, (string) $pendingRequest->uri())) {
                return $this->resolveValue($response, $pendingRequest);
            }
        }

        if ($this->sequenceResponses !== []) {
            /** @var callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse $response */
            $response = array_shift($this->sequenceResponses);

            return $this->resolveValue($response, $pendingRequest);
        }

        if ($this->allowsStrayRequest($pendingRequest)) {
            return null;
        }

        throw new NoMockResponseFoundException($pendingRequest);
    }

    /**
     * Determine if no mock responses remain.
     */
    public function isEmpty(): bool
    {
        return $this->sequenceResponses === []
            && $this->connectorResponses === []
            && $this->requestResponses === []
            && $this->urlResponses === [];
    }

    /**
     * Prevent unmatched requests from reaching the network.
     *
     * @return $this
     */
    public function preventStrayRequests(bool $prevent = true): static
    {
        $this->preventStrayRequests = $prevent;
        $this->allowedStrayRequestUrls = $prevent ? [] : null;

        return $this;
    }

    /**
     * Allow unmatched requests to continue through the normal send lifecycle.
     *
     * @param null|list<string> $only
     * @return $this
     */
    public function allowStrayRequests(?array $only = null): static
    {
        $this->preventStrayRequests = false;
        $this->allowedStrayRequestUrls = $only;

        return $this;
    }

    /**
     * Record a response.
     */
    public function recordResponse(Response $response): void
    {
        $this->recordedResponses[] = $response;
    }

    /**
     * Get recorded responses, optionally filtered by a callback.
     *
     * @return Collection<int, Response>
     */
    public function recorded(?callable $callback = null): Collection
    {
        $responses = new Collection($this->recordedResponses);

        return $callback === null
            ? $responses
            : $responses->filter(
                fn (Response $response): bool => (bool) $callback($response->request(), $response),
            )->values();
    }

    /**
     * Get the last recorded request.
     */
    public function lastRequest(): ?Request
    {
        return $this->lastResponse()?->request();
    }

    /**
     * Get the last recorded pending request.
     */
    public function lastPendingRequest(): ?PendingRequest
    {
        return $this->lastResponse()?->pendingRequest();
    }

    /**
     * Get the last recorded response.
     */
    public function lastResponse(): ?Response
    {
        return $this->recordedResponses[array_key_last($this->recordedResponses)] ?? null;
    }

    /**
     * Assert that a matching request was sent.
     */
    public function assertSent(string|callable $value): void
    {
        PHPUnit::assertTrue($this->requestWasSent($value), 'An expected request was not sent.');
    }

    /**
     * Assert that no matching request was sent.
     */
    public function assertNotSent(string|callable $value): void
    {
        PHPUnit::assertFalse($this->requestWasSent($value), 'An unexpected request was sent.');
    }

    /**
     * Assert that requests were sent in order.
     *
     * @param list<callable|string> $callbacks
     */
    public function assertSentInOrder(array $callbacks): void
    {
        $this->assertSentCount(count($callbacks));

        foreach ($callbacks as $index => $callback) {
            PHPUnit::assertTrue(
                $this->responseMatches($this->recordedResponses[$index], $callback),
                'An expected request (#' . ($index + 1) . ') was not sent.',
            );
        }
    }

    /**
     * Assert that nothing was sent.
     */
    public function assertNothingSent(): void
    {
        PHPUnit::assertEmpty($this->recordedResponses, 'Requests were sent.');
    }

    /**
     * Assert the number of recorded requests.
     *
     * @param null|class-string<Request> $requestClass
     */
    public function assertSentCount(int $count, ?string $requestClass = null): void
    {
        $responses = $requestClass === null
            ? $this->recordedResponses
            : array_filter(
                $this->recordedResponses,
                fn (Response $response): bool => $response->request() instanceof $requestClass,
            );

        PHPUnit::assertCount($count, $responses);
    }

    /**
     * Register or retrieve the manager-backed global mock client.
     *
     * Tests only. The client persists until the test application is destroyed
     * or `destroyGlobal()` is called.
     *
     * @param array<array-key, callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse> $mockData
     */
    public static function global(array $mockData = []): MockClient
    {
        $manager = static::manager();

        return $manager->mockClient() ?? $manager->fake($mockData);
    }

    /**
     * Get the manager-backed global mock client.
     */
    public static function getGlobal(): ?MockClient
    {
        return static::manager()->mockClient();
    }

    /**
     * Destroy the manager-backed global mock client.
     */
    public static function destroyGlobal(): void
    {
        static::manager()->clearFake();
    }

    /**
     * Determine if any recorded response matches the request condition.
     */
    protected function requestWasSent(string|callable $value): bool
    {
        foreach ($this->recordedResponses as $response) {
            if ($this->responseMatches($response, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the recorded response matches the request condition.
     */
    protected function responseMatches(Response $response, string|callable $value): bool
    {
        $request = $response->request();

        if (is_string($value)) {
            return is_a($value, Request::class, true)
                ? $request instanceof $value
                : Str::is($value, (string) $response->pendingRequest()->uri());
        }

        $closure = $value(...);
        $parameters = (new ReflectionFunction($closure))->getParameters();

        if ($parameters !== [] && $parameters[0]->hasType()) {
            $types = $this->firstClosureParameterTypes($closure);

            if (! array_any($types, fn (string $type): bool => $request instanceof $type)) {
                return false;
            }
        }

        return (bool) $closure($request, $response);
    }

    /**
     * Determine if the pending request may continue without a fake.
     */
    protected function allowsStrayRequest(PendingRequest $pendingRequest): bool
    {
        if ($this->preventStrayRequests) {
            return false;
        }

        if ($this->allowedStrayRequestUrls === null) {
            return true;
        }

        return array_any(
            $this->allowedStrayRequestUrls,
            fn (string $url): bool => Str::is($url, (string) $pendingRequest->uri()),
        );
    }

    /**
     * Resolve a configured response value.
     *
     * @param callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse $value
     */
    protected function resolveValue(MockResponse|Fixture|callable $value, PendingRequest $pendingRequest): MockResponse|Fixture
    {
        return is_callable($value) ? $value($pendingRequest) : $value;
    }

    /**
     * Resolve the Saloon manager.
     */
    protected static function manager(): SaloonManager
    {
        /** @var SaloonManager */
        return Container::getInstance()->make('saloon');
    }
}
