<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use BadMethodCallException;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use Hypervel\ApiClient\ApiClient;
use Hypervel\ApiClient\ApiRequest;
use Hypervel\ApiClient\ApiResource;
use Hypervel\ApiClient\ApiResponse;
use Hypervel\ApiClient\PendingRequest;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Events\RequestSending;
use Hypervel\Http\Client\PendingRequest as HttpPendingRequest;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class PendingRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function testApiMiddlewareAcceptsEveryPipelineShapeAndContainerInjection(): void
    {
        $dependency = new ApiClientMiddlewareDependency;
        $this->app->instance(ApiClientMiddlewareDependency::class, $dependency);
        $objectMiddleware = new ApiClientObjectMiddleware;

        Http::fake(['https://example.test/test' => Http::response(['ok' => true])]);

        $resource = (new ApiClient)
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next): ApiRequest {
                return $next($request->withHeader('X-Closure', 'yes'));
            })
            ->withApiRequestMiddleware(__NAMESPACE__ . '\apiClientCallableMiddleware')
            ->withApiRequestMiddleware([ApiClientStaticMiddleware::class, 'handle'])
            ->withApiRequestMiddleware($objectMiddleware)
            ->withApiRequestMiddleware(ApiClientInjectedMiddleware::class)
            ->withApiResponseMiddleware(ApiClientInjectedResponseMiddleware::class)
            ->get('https://example.test/test');

        $this->assertTrue($objectMiddleware->called);
        $this->assertSame($dependency, $resource->context('dependency'));
        $this->assertSame('response', $resource->context('phase'));
        Http::assertSent(fn (Request $request) => $request->hasHeaders([
            'X-Closure' => 'yes',
            'X-Callable-String' => 'yes',
            'X-Callable-Array' => 'yes',
            'X-Object' => 'yes',
            'X-Injected' => 'yes',
        ]));
    }

    public function testApiMiddlewareCanBeReplacedOrRemoved(): void
    {
        $calls = [];
        Http::fake(['https://example.test/*' => Http::response([])]);

        $pending = (new ApiClient)
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next) use (&$calls): ApiRequest {
                $calls[] = 'old-request';

                return $next($request);
            })
            ->replaceApiRequestMiddleware([
                function (ApiRequest $request, callable $next) use (&$calls): ApiRequest {
                    $calls[] = 'new-request';

                    return $next($request);
                },
            ])
            ->withApiResponseMiddleware(function (ApiResponse $response, callable $next) use (&$calls): ApiResponse {
                $calls[] = 'old-response';

                return $next($response);
            })
            ->replaceApiResponseMiddleware([
                function (ApiResponse $response, callable $next) use (&$calls): ApiResponse {
                    $calls[] = 'new-response';

                    return $next($response);
                },
            ]);

        $pending->get('https://example.test/first');

        $this->assertSame(['new-request', 'new-response'], $calls);

        $calls = [];
        $pending->withoutApiMiddleware()->get('https://example.test/second');

        $this->assertSame([], $calls);
    }

    public function testContainerControlsClassMiddlewareLifetimes(): void
    {
        $tracker = new ApiClientMiddlewareTracker;
        $this->app->instance(ApiClientMiddlewareTracker::class, $tracker);
        $this->app->bind(
            ApiClientTransientMiddleware::class,
            fn () => new ApiClientTransientMiddleware($tracker),
        );
        Http::fake(['https://example.test/*' => Http::response([])]);

        $client = new ApiClient;

        $client->withApiRequestMiddleware(ApiClientAutoSingletonMiddleware::class)
            ->get('https://example.test/one');
        $client->withApiRequestMiddleware(ApiClientAutoSingletonMiddleware::class)
            ->get('https://example.test/two');
        $client->withApiRequestMiddleware(ApiClientTransientMiddleware::class)
            ->get('https://example.test/three');
        $client->withApiRequestMiddleware(ApiClientTransientMiddleware::class)
            ->get('https://example.test/four');

        $this->assertCount(2, $tracker->autoSingletonInstances);
        $this->assertSame(
            $tracker->autoSingletonInstances[0],
            $tracker->autoSingletonInstances[1],
        );
        $this->assertNotSame(
            $tracker->transientInstances[0],
            $tracker->transientInstances[1],
        );
    }

    public function testForwardingPreservesFluentAndValueReturnsAndUnshadowsHttpMiddleware(): void
    {
        Http::fake(['https://example.test/test' => Http::response([])]);

        $pending = (new ApiClient)->createPendingRequest();

        $this->assertSame($pending, $pending->withOptions(['timeout' => 5]));
        $this->assertSame(5, $pending->getOptions()['timeout']);

        $pending
            ->withOptions(['timeout' => 10])
            ->withRequestMiddleware(
                fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Guzzle', 'yes')
            )
            ->get('https://example.test/test');

        $this->assertSame(10, $pending->getOptions()['timeout']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Guzzle', 'yes'));
    }

    public function testDefaultResponsesDoNotThrowAndThrowRemainsOptIn(): void
    {
        Http::fake([
            'https://example.test/default' => Http::response(['error' => true], 500),
            'https://example.test/throw' => Http::response(['error' => true], 500),
        ]);

        $response = (new ApiClient)->get('https://example.test/default');

        $this->assertSame(500, $response->status());

        $this->expectException(RequestException::class);

        (new ApiClient)->throw()->get('https://example.test/throw');
    }

    public function testAsyncIsRejectedBeforeTheHttpBuilderIsCreated(): void
    {
        $pending = new ApiClientInspectablePendingRequest;

        try {
            $pending->async();
            $this->fail('Expected asynchronous dispatch to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The API client does not support asynchronous requests.',
                $exception->getMessage(),
            );
        }

        $this->assertFalse($pending->clientWasCreated);
        $this->assertSame($pending, $pending->async(false));
    }

    public function testCustomGuzzleClientIsRejectedBeforeTransportDispatch(): void
    {
        $transportInvocations = 0;
        $client = new Client([
            'handler' => function () use (&$transportInvocations): never {
                ++$transportInvocations;

                throw new RuntimeException('The custom transport was invoked.');
            },
        ]);

        try {
            (new ApiClient)
                ->setClient($client)
                ->get('https://example.test/custom-client');
            $this->fail('Expected custom Guzzle clients to be rejected.');
        } catch (BadMethodCallException $exception) {
            $this->assertSame(
                'Custom Guzzle clients are not supported by the API client. Use setHandler() to configure a request-specific transport handler.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, $transportInvocations);
    }

    public function testCustomHandlerPreservesApiMiddlewareAndResourceConstruction(): void
    {
        $middlewareRan = false;
        Http::preventStrayRequests(false);

        $resource = (new ApiClient)
            ->setHandler(fn () => Http::response(['ok' => true]))
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next) use (&$middlewareRan): ApiRequest {
                $middlewareRan = true;

                return $next($request);
            })
            ->get('https://example.test/custom-handler');

        $this->assertTrue($middlewareRan);
        $this->assertInstanceOf(ApiResource::class, $resource);
        $this->assertTrue($resource['ok']);
    }

    public function testOneBridgeRunsOncePerDispatchAndRetryAttempt(): void
    {
        $sequence = Http::sequence()
            ->push(['attempt' => 1], 500)
            ->push(['attempt' => 2]);
        Http::fake([
            'https://example.test/retry' => $sequence,
            'https://example.test/again' => Http::response([]),
        ]);
        $attempts = 0;
        $pending = (new ApiClient)
            ->withContext('tenant', 'tenant-1')
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next) use (&$attempts): ApiRequest {
                ++$attempts;

                return $next($request->withContext('attempt', $attempts));
            })
            ->retry(2, 0);

        $resource = $pending->get('https://example.test/retry');

        $this->assertSame(2, $attempts);
        $this->assertSame(2, $resource->context('attempt'));
        $this->assertSame('tenant-1', $resource->context('tenant'));

        $pending->get('https://example.test/again');

        $this->assertSame(3, $attempts);
    }

    public function testApiBridgeRunsBeforeBeforeSendingCallbacksWithoutReorderingThem(): void
    {
        $order = [];
        Http::fake(['https://example.test/*' => Http::response([])]);
        $pending = (new ApiClient)
            ->beforeSending(function (Request $request) use (&$order): RequestInterface {
                $order[] = 'caller';

                return $request->toPsrRequest()->withHeader('X-Before', 'yes');
            })
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next) use (&$order): ApiRequest {
                $order[] = 'api';

                return $next($request);
            });

        $pending->get('https://example.test/first');

        $this->assertSame(['api', 'caller'], $order);

        $pending->beforeSending(function (Request $request) use (&$order): RequestInterface {
            $order[] = 'late';

            return $request->toPsrRequest()->withHeader('X-Late', 'yes');
        });
        $resource = $pending->get('https://example.test/second');

        $this->assertSame(['api', 'caller', 'api', 'caller', 'late'], $order);
        $this->assertFalse($resource->getRequest()->hasHeader('X-Late'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/second'
            && $request->hasHeader('X-Late', 'yes'));
    }

    public function testApiBridgeRunsBeforeOrdinaryGuzzleShortCircuits(): void
    {
        $middlewareRan = false;

        $resource = (new ApiClient)
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next) use (&$middlewareRan): ApiRequest {
                $middlewareRan = true;

                return $next($request->withContext('source', 'api-middleware'));
            })
            ->withMiddleware(static function (callable $handler): callable {
                return static fn (): PromiseInterface => Create::promiseFor(new Psr7Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"cached":true}',
                ));
            })
            ->get('https://example.test/cached');

        $this->assertTrue($middlewareRan);
        $this->assertTrue($resource['cached']);
        $this->assertSame('api-middleware', $resource->context('source'));
    }

    public function testShortCircuitAheadOfTheApiBridgeFailsClearly(): void
    {
        $pending = (new ApiClient)->createPendingRequest();
        $pending->prependMiddleware(static function (callable $handler): callable {
            return static fn (): PromiseInterface => Create::promiseFor(new Psr7Response(200));
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('middleware ahead of the API bridge short-circuited');

        $pending->get('https://example.test/short-circuit');
    }

    public function testShortCircuitAheadOfTheApiBridgeOnRetryDoesNotReuseThePreviousAttempt(): void
    {
        $transportCalls = 0;
        Http::fake(function () use (&$transportCalls) {
            ++$transportCalls;

            return Http::response([], 500);
        });

        $middlewareAttempts = 0;
        $bridgeAttempts = 0;
        $pending = (new ApiClient)
            ->createPendingRequest()
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next) use (&$bridgeAttempts): ApiRequest {
                ++$bridgeAttempts;

                return $next($request->withContext('attempt', $bridgeAttempts));
            })
            ->retry(2, 0);

        $this->assertSame($pending, $pending->prependMiddleware(
            static function (callable $handler) use (&$middlewareAttempts): callable {
                return static function (RequestInterface $request, array $options) use ($handler, &$middlewareAttempts): PromiseInterface {
                    ++$middlewareAttempts;

                    return $middlewareAttempts === 2
                        ? Create::promiseFor(new Psr7Response(200))
                        : $handler($request, $options);
                };
            }
        ));

        $exception = null;

        try {
            $pending->get('https://example.test/retry-short-circuit');
        } catch (LogicException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertSame(
            'HTTP middleware ahead of the API bridge short-circuited the request before API middleware could run.',
            $exception->getMessage(),
        );
        $this->assertSame(2, $middlewareAttempts);
        $this->assertSame(1, $bridgeAttempts);
        $this->assertSame(1, $transportCalls);
    }

    public function testBridgePreservesLogicalDataAttributesAndApiContext(): void
    {
        $observed = null;
        Http::fake(['https://example.test/context' => Http::response([])]);

        (new ApiClient)
            ->withAttributes(['trace' => 'request-1'])
            ->withContext('tenant', 'tenant-1')
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next) use (&$observed): ApiRequest {
                $observed = [
                    'data' => $request->data(),
                    'attributes' => $request->attributes(),
                    'tenant' => $request->context('tenant'),
                ];

                return $next($request);
            })
            ->post('https://example.test/context', ['name' => 'Taylor']);

        $this->assertSame([
            'data' => ['name' => 'Taylor'],
            'attributes' => ['trace' => 'request-1'],
            'tenant' => 'tenant-1',
        ], $observed);
    }

    public function testRequestSendingObservesThePostApiMiddlewareRequest(): void
    {
        $observedHeader = false;
        Event::listen(RequestSending::class, function (RequestSending $event) use (&$observedHeader): void {
            $observedHeader = $event->request->hasHeader('X-Api-Middleware', 'yes');
        });
        Http::fake(['https://example.test/event' => Http::response([])]);

        (new ApiClient)
            ->withApiRequestMiddleware(fn (ApiRequest $request, callable $next): ApiRequest => $next(
                $request->withHeader('X-Api-Middleware', 'yes')
            ))
            ->get('https://example.test/event');

        $this->assertTrue($observedHeader);
    }

    public function testUnboundPendingRequestContainerResolutionsAreFresh(): void
    {
        $first = $this->app->make(PendingRequest::class)->withContext('tenant', 'tenant-1');
        $second = $this->app->make(PendingRequest::class);

        $this->assertNotSame($first, $second);
        $this->assertSame([], $second->context());
    }

    public function testApiMiddlewareMutatesTheFinalRawHttpBody(): void
    {
        Http::fake(['https://example.test/raw' => Http::response([])]);

        (new ApiClient)
            ->withBody('{"existing":true}')
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next): ApiRequest {
                return $next($request->mergeData(['added' => true]));
            })
            ->post('https://example.test/raw');

        Http::assertSent(fn (Request $request) => $request->body() === '{"existing":true,"added":true}'
            && $request->data() === ['existing' => true, 'added' => true]);
    }

    public function testBeforeSendingBodyReplacementRunsAfterApiMiddleware(): void
    {
        Http::fake(['https://example.test/callback' => Http::response([])]);

        $resource = (new ApiClient)
            ->beforeSending(fn (Request $request): RequestInterface => $request->toPsrRequest()->withBody(
                Utils::streamFor('{"replaced":true}')
            ))
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next): ApiRequest {
                return $next($request->mergeData(['added' => true]));
            })
            ->post('https://example.test/callback', ['original' => true]);

        $this->assertSame(['original' => true, 'added' => true], $resource->getRequest()->data());
        Http::assertSent(fn (Request $request) => $request->body() === '{"replaced":true}'
            && $request->data() === ['replaced' => true]);
    }

    public function testOrdinaryGuzzleMiddlewareRunsAfterApiMiddleware(): void
    {
        Http::fake(['https://example.test/guzzle' => Http::response([])]);

        $resource = (new ApiClient)
            ->withRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withBody(
                Utils::streamFor('{"middleware":true}')
            ))
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next): ApiRequest {
                return $next($request->mergeData(['added' => true]));
            })
            ->post('https://example.test/guzzle', ['original' => true]);

        $this->assertSame(['original' => true, 'added' => true], $resource->getRequest()->data());
        Http::assertSent(fn (Request $request) => $request->body() === '{"middleware":true}'
            && $request->data() === ['middleware' => true]);
    }

    public function testApiMiddlewarePreservesLogicalDataUntilItChangesTheBody(): void
    {
        $payload = ['whole_number_float' => 1.0];
        $observedRequest = null;
        Http::fake(['https://example.test/*' => Http::response([])]);

        $resource = (new ApiClient)
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next): ApiRequest {
                return $next($request->withHeader('X-Test', 'yes'));
            })
            ->post('https://example.test/header', $payload);

        $this->assertSame($payload, $resource->getRequest()->data());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/header'
            && $request->data() === $payload);

        (new ApiClient)
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next): ApiRequest {
                return $next($request->mergeData(['added' => true]));
            })
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next) use (&$observedRequest): ApiRequest {
                $observedRequest = [
                    'data' => $request->data(),
                    'body' => $request->body(),
                    'content_length' => $request->header('Content-Length'),
                ];

                return $next($request);
            })
            ->post('https://example.test/body', $payload);

        $expectedBody = '{"whole_number_float":1,"added":true}';
        $this->assertSame([
            'data' => ['whole_number_float' => 1.0, 'added' => true],
            'body' => $expectedBody,
            'content_length' => [(string) strlen($expectedBody)],
        ], $observedRequest);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/body'
            && $request->data() === ['whole_number_float' => 1, 'added' => true]);
    }

    public function testTransientRequestStateIsClearedAfterEveryFailureBoundary(): void
    {
        Http::fake([
            'https://example.test/request' => Http::response([]),
            'https://example.test/response' => Http::response([]),
            'https://example.test/resource' => Http::response([]),
            'https://example.test/transport' => Http::failedConnection('failed'),
        ]);
        $pending = new ApiClientInspectablePendingRequest;

        $requestException = new RuntimeException('request');
        $pending->withApiRequestMiddleware(fn (): never => throw $requestException);
        $caughtException = null;

        try {
            $pending->get('https://example.test/request');
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        }

        $this->assertSame($requestException, $caughtException);
        $this->assertNull($pending->activeRequest());

        $responseException = new RuntimeException('response');
        $pending
            ->replaceApiRequestMiddleware([])
            ->withApiResponseMiddleware(fn (): never => throw $responseException);
        $caughtException = null;

        try {
            $pending->get('https://example.test/response');
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        }

        $this->assertSame($responseException, $caughtException);
        $this->assertNull($pending->activeRequest());

        $pending
            ->replaceApiResponseMiddleware([])
            ->withResource(ApiClientThrowingResource::class);
        $caughtException = null;

        try {
            $pending->get('https://example.test/resource');
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        }

        $this->assertNotNull($caughtException);
        $this->assertSame(RuntimeException::class, $caughtException::class);
        $this->assertSame('resource', $caughtException->getMessage());
        $this->assertNull($pending->activeRequest());

        $pending->withResource(ApiResource::class);
        $caughtException = null;

        try {
            $pending->get('https://example.test/transport');
        } catch (ConnectionException $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(ConnectionException::class, $caughtException);
        $this->assertNull($pending->activeRequest());
    }

    #[DataProvider('terminalProvider')]
    public function testEveryTerminalReturnsTheConfiguredResource(string $terminal, array $arguments): void
    {
        Http::fake(['https://example.test/terminal' => Http::response([])]);

        $resource = (new ApiClient)
            ->withResource(PendingRequestTestResource::class)
            ->{$terminal}(...$arguments);

        $this->assertInstanceOf(PendingRequestTestResource::class, $resource);
    }

    public static function terminalProvider(): array
    {
        $url = 'https://example.test/terminal';

        return [
            'get' => ['get', [$url]],
            'head' => ['head', [$url]],
            'query' => ['query', [$url, []]],
            'post' => ['post', [$url, []]],
            'patch' => ['patch', [$url, []]],
            'put' => ['put', [$url, []]],
            'delete' => ['delete', [$url, []]],
            'send' => ['send', ['OPTIONS', $url]],
        ];
    }

    public function testOmittedGetAndHeadQueriesPreserveConfiguredParameters(): void
    {
        Http::fake(['https://example.test/*' => Http::response([])]);

        (new ApiClient)
            ->withQueryParameters(['configured' => 'yes'])
            ->get('https://example.test/get');
        (new ApiClient)
            ->withQueryParameters(['configured' => 'yes'])
            ->head('https://example.test/head');
        (new ApiClient)
            ->withQueryParameters(['configured' => 'yes'])
            ->get('https://example.test/null', null);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/get?configured=yes');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/head?configured=yes');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/null');
    }

    public function testHeadAcceptsRecursiveJsonSerializableQueryData(): void
    {
        Http::fake(['https://example.test/head*' => Http::response([])]);

        (new ApiClient)->head('https://example.test/head', new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return [
                    'payload' => new class implements JsonSerializable {
                        public function jsonSerialize(): mixed
                        {
                            return ['name' => 'Taylor'];
                        }
                    },
                ];
            }
        });

        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/head?payload%5Bname%5D=Taylor'
            && $request->data() === ['payload' => ['name' => 'Taylor']]);
    }

    #[DataProvider('bodylessFormRequestProvider')]
    public function testFormConfiguredClientSupportsBodylessRequests(
        string $method,
        array $arguments,
        string $expectedUrl,
        array $expectedData,
    ): void {
        Http::fake(['https://example.test/*' => Http::response([])]);

        (new ApiClientFormConfiguredClient)->{$method}(...$arguments);

        Http::assertSent(function (Request $request) use ($expectedUrl, $expectedData) {
            return $request->url() === $expectedUrl
                && $request->data() === $expectedData;
        });
    }

    public static function bodylessFormRequestProvider(): array
    {
        return [
            'GET' => ['get', ['https://example.test/get'], 'https://example.test/get', []],
            'GET with query' => ['get', ['https://example.test/get', ['foo' => 'bar']], 'https://example.test/get?foo=bar', ['foo' => 'bar']],
            'HEAD' => ['head', ['https://example.test/head'], 'https://example.test/head', []],
            'DELETE' => ['delete', ['https://example.test/delete'], 'https://example.test/delete', []],
        ];
    }

    public function testApiMiddlewareReceivesNormalizedNestedFormData(): void
    {
        $observedData = null;
        Http::fake(['https://example.test/form' => Http::response([])]);

        (new ApiClient)
            ->asForm()
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next) use (&$observedData): ApiRequest {
                $observedData = $request->data();

                return $next($request);
            })
            ->post('https://example.test/form', [
                'payload' => new class implements JsonSerializable {
                    public function jsonSerialize(): mixed
                    {
                        return ['name' => 'Taylor'];
                    }
                },
            ]);

        $this->assertSame(['payload' => ['name' => 'Taylor']], $observedData);
        Http::assertSent(fn (Request $request) => $request->body() === 'payload%5Bname%5D=Taylor'
            && $request->data() === ['payload' => ['name' => 'Taylor']]);
    }

    #[DataProvider('emptyJsonReadMethodProvider')]
    public function testApiMiddlewareSeesEmptyDataForJsonReadRequestsWithoutABody(string $method): void
    {
        $observedData = null;
        Http::fake(['https://example.test' => Http::response([])]);

        (new ApiClient)
            ->asJson()
            ->withApiRequestMiddleware(function (ApiRequest $request, callable $next) use (&$observedData): ApiRequest {
                $observedData = $request->data();

                return $next($request);
            })
            ->{$method}('https://example.test');

        $this->assertSame([], $observedData);
    }

    public static function emptyJsonReadMethodProvider(): array
    {
        return [
            'GET' => ['get'],
            'HEAD' => ['head'],
        ];
    }

    public function testResourceSelectionAcceptsTheBaseAndSubclassesAndRejectsOtherClasses(): void
    {
        Http::fake(['https://example.test/*' => Http::response([])]);

        $this->assertInstanceOf(
            ApiResource::class,
            (new ApiClient)->withResource(ApiResource::class)->get('https://example.test/base'),
        );
        $this->assertInstanceOf(
            PendingRequestTestResource::class,
            (new ApiClient)->withResource(PendingRequestTestResource::class)->get('https://example.test/subclass'),
        );

        $this->expectException(InvalidArgumentException::class);

        (new ApiClient)->withResource(self::class);
    }
}

function apiClientCallableMiddleware(ApiRequest $request, callable $next): ApiRequest
{
    return $next($request->withHeader('X-Callable-String', 'yes'));
}

class ApiClientStaticMiddleware
{
    public static function handle(ApiRequest $request, callable $next): ApiRequest
    {
        return $next($request->withHeader('X-Callable-Array', 'yes'));
    }
}

class ApiClientObjectMiddleware
{
    public bool $called = false;

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        $this->called = true;

        return $next($request->withHeader('X-Object', 'yes'));
    }
}

class ApiClientMiddlewareDependency
{
}

class ApiClientInjectedMiddleware
{
    public function __construct(protected ApiClientMiddlewareDependency $dependency)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        return $next(
            $request
                ->withHeader('X-Injected', 'yes')
                ->withContext('dependency', $this->dependency)
        );
    }
}

class ApiClientInjectedResponseMiddleware
{
    public function __construct(protected ApiClientMiddlewareDependency $dependency)
    {
    }

    public function handle(ApiResponse $response, callable $next): ApiResponse
    {
        return $next(
            $response
                ->withContext('dependency', $this->dependency)
                ->withContext('phase', 'response')
        );
    }
}

class ApiClientMiddlewareTracker
{
    public array $autoSingletonInstances = [];

    public array $transientInstances = [];
}

class ApiClientAutoSingletonMiddleware
{
    public function __construct(protected ApiClientMiddlewareTracker $tracker)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        $this->tracker->autoSingletonInstances[] = $this;

        return $next($request);
    }
}

class ApiClientTransientMiddleware
{
    public function __construct(protected ApiClientMiddlewareTracker $tracker)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        $this->tracker->transientInstances[] = $this;

        return $next($request);
    }
}

class ApiClientFormConfiguredClient extends ApiClient
{
    protected function configurePendingRequest(PendingRequest $request): void
    {
        $request->asForm();
    }
}

class ApiClientInspectablePendingRequest extends PendingRequest
{
    public bool $clientWasCreated = false;

    public function activeRequest(): ?ApiRequest
    {
        return $this->activeRequest;
    }

    protected function getRequest(): HttpPendingRequest
    {
        $this->clientWasCreated = true;

        return parent::getRequest();
    }
}

class ApiClientThrowingResource extends ApiResource
{
    public static function make(ApiResponse $response, ApiRequest $request): static
    {
        throw new RuntimeException('resource');
    }
}

class PendingRequestTestResource extends ApiResource
{
}
