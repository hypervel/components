<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Events\SendingSaloonRequest;
use Hypervel\Saloon\Exceptions\InvalidHeaderException;
use Hypervel\Saloon\Exceptions\MissingAuthenticatorException;
use Hypervel\Saloon\Exceptions\PendingRequestException;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Auth\CookieAuthenticator;
use Hypervel\Saloon\Http\Auth\HeaderAuthenticator;
use Hypervel\Saloon\Http\Auth\QueryAuthenticator;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Repositories\Body\StringBodyRepository;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Saloon\Traits\Auth\RequiresAuth;
use Hypervel\Saloon\Traits\Body\HasJsonBody;
use Hypervel\Saloon\Traits\Body\HasStringBody;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Stringable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\StreamInterface;
use stdClass;

class PendingRequestTest extends TestCase
{
    public function testYouCanOverwriteTheUrlAndTheMethodOfThePendingRequest(): void
    {
        Http::fake(['*' => Http::response(['name' => 'Sam'])]);

        $connector = new TestConnector;
        $request = new UserRequest;

        // Connectors are read-only, so the middleware is registered on the request instead of the connector.
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest): void {
            $pendingRequest->withUrl('https://other-endpoint.co.uk' . $pendingRequest->request()->resolveEndpoint());
            $pendingRequest->withMethod(Method::POST);
        });

        $this->assertSame(Method::GET, $request->method());

        $response = $connector->send($request);
        $pendingRequest = $response->pendingRequest();

        $this->assertSame('https://other-endpoint.co.uk/user', (string) $pendingRequest->uri());
        $this->assertSame(Method::POST, $pendingRequest->method());
        $this->assertSame(Method::GET, $request->method());
        $this->assertNull($request->url());
        Http::assertSent(fn (HttpRequest $sent): bool => $sent->method() === 'POST'
            && $sent->url() === 'https://other-endpoint.co.uk/user');
    }

    public function testThePendingRequestIsMacroable(): void
    {
        PendingRequest::macro('yee', fn (): string => 'haw');

        $pendingRequest = (new TestConnector)->createPendingRequest(new UserRequest);

        $this->assertSame('haw', $pendingRequest->yee());
    }

    public function testThePendingRequestValidatesProperlyFormedHeaders(): void
    {
        $request = (new UserRequest)->withHeaders([
            'Content-Type: application/json',
        ]);

        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage('One or more of the headers are invalid. Make sure to use the header name as the key. For example: [\'Content-Type\' => \'application/json\'].');

        (new TestConnector)->createPendingRequest($request);
    }

    public function testHeadersWrittenAsAListAreRejectedBeforePluginsMatchHeaderNames(): void
    {
        $request = (new PendingRequestRequestStub)->withHeaders(['Accept: application/json']);

        $this->expectException(InvalidHeaderException::class);

        (new PendingRequestConnectorStub)->createPendingRequest($request);
    }

    public function testHeaderValuesAreNormalizedForTheOutgoingRequest(): void
    {
        $pendingRequest = (new TestConnector)->createPendingRequest((new UserRequest)->withHeaders([
            'X-Null' => null,
            'X-Empty' => [],
            'X-List' => ['one', 2, true],
            'X-Stringable' => new Stringable('value'),
            'X-Float' => 1.5,
            'X-Not-A-Number' => NAN,
            'X-Infinite' => -INF,
        ]));

        $headers = $pendingRequest->toPsrRequest()->getHeaders();

        $this->assertSame([''], $headers['X-Null']);
        $this->assertSame([''], $headers['X-Empty']);
        $this->assertSame(['one', '2', '1'], $headers['X-List']);
        $this->assertSame(['value'], $headers['X-Stringable']);
        $this->assertSame(['1.5'], $headers['X-Float']);
        $this->assertSame(['NAN'], $headers['X-Not-A-Number']);
        $this->assertSame(['-INF'], $headers['X-Infinite']);
    }

    public function testHeaderValuesMustBeScalarNullOrStringable(): void
    {
        $pendingRequest = (new TestConnector)->createPendingRequest(
            (new UserRequest)->withHeader('X-Object', new stdClass),
        );

        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage('HTTP header values must be scalar, null, Hypervel Stringable, or arrays of those values.');

        $pendingRequest->toPsrRequest();
    }

    public function testPreparingARequestRunsItsHooksAndMiddlewareWithoutSendingIt(): void
    {
        Http::fake();
        Event::fake([SendingSaloonRequest::class]);

        $request = (new PendingRequestRequestStub)->withData(['name' => 'Taylor']);
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest): void {
            ++PendingRequestRequestStub::$middlewareCalls;
        });

        $pendingRequest = (new PendingRequestConnectorStub)->createPendingRequest($request);

        $this->assertSame(1, PendingRequestConnectorStub::$bootCalls);
        $this->assertSame(1, PendingRequestRequestStub::$bootCalls);
        $this->assertSame(1, PendingRequestRequestStub::$middlewareCalls);
        $this->assertSame('application/json', $pendingRequest->headers()['Content-Type']);
        $this->assertNull($pendingRequest->preparedBody());
        Event::assertNotDispatched(SendingSaloonRequest::class);
        Http::assertNothingSent();
    }

    public function testUrlParametersAreExpandedWithoutChangingTheRequest(): void
    {
        $request = (new PendingRequestRequestStub)
            ->withUrl('https://{region}.example.com/{version}/users')
            ->withUrlParameters(['region' => 'eu', 'version' => 'v1']);
        $pendingRequest = $this->pendingRequest(new PendingRequestConnectorWithoutBodyStub, $request);

        $this->assertSame('https://eu.example.com/v1/users', (string) $pendingRequest->finalizeUri()->uri());

        $pendingRequest->withUrlParameters(['version' => 'v2']);

        $this->assertSame('https://eu.example.com/v2/users', (string) $pendingRequest->uri());
        $this->assertSame(['region' => 'eu', 'version' => 'v1'], $request->urlParameters());
    }

    public function testUrlParametersAreExpandedInTheBaseUrlAndEndpoint(): void
    {
        $request = (new PendingTemplateRequestStub)->withUrlParameters(['tenant' => 'acme', 'id' => 'a b/c']);
        $pendingRequest = $this->pendingRequest(new PendingTemplateConnectorStub, $request);

        $this->assertSame('https://acme.example.com/v1/users/a%20b%2Fc', (string) $pendingRequest->uri());
    }

    public function testConstructionOnlySnapshotsOperationState(): void
    {
        $connector = new PendingRequestConnectorStub;
        $request = (new PendingRequestRequestStub)
            ->withHeader('X-Request', 'request')
            ->withQueryParameters(['page' => 2])
            ->withOptions(['allow_redirects' => ['max' => 3]])
            ->delay(20)
            ->withData(['name' => 'Taylor']);
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest): void {
            ++PendingRequestRequestStub::$middlewareCalls;
        });

        $pendingRequest = $this->pendingRequest($connector, $request);

        $this->assertSame(0, PendingRequestConnectorStub::$bootCalls);
        $this->assertSame(0, PendingRequestRequestStub::$bootCalls);
        $this->assertSame(0, PendingRequestRequestStub::$middlewareCalls);
        $this->assertSame([
            'X-Connector' => 'connector',
            'X-Request' => 'request',
        ], $pendingRequest->headers());
        $this->assertSame(['version' => 1, 'page' => 2], $pendingRequest->queryParameters());
        $this->assertSame(['strict' => true, 'max' => 3], $pendingRequest->options()['allow_redirects']);
        $this->assertSame(20, $pendingRequest->delayMilliseconds());
        $this->assertSame(['connector' => true, 'name' => 'Taylor'], $pendingRequest->body());
    }

    public function testUriAndBodyAreFinalizedAfterRequestMiddleware(): void
    {
        $request = (new PendingRequestRequestStub)->withData(['initial' => true]);
        $request->middleware()->onRequest(static function (PendingRequest $pendingRequest): void {
            $pendingRequest
                ->withQueryParameters(['page' => 3])
                ->withData(['middleware' => true]);
        });
        $pendingRequest = $this->pendingRequest(new PendingRequestConnectorStub, $request);

        $pendingRequest->executeRequestPipeline()->finalizeUri()->prepareBody();

        $this->assertSame('https://api.example.com/v1/users?version=1&page=3', (string) $pendingRequest->uri());
        $this->assertSame(
            '{"connector":true,"initial":true,"middleware":true}',
            (string) $pendingRequest->preparedBody(),
        );
    }

    public function testRawQuerySnapshotsDefaultsAndReplacesBothUrlQueries(): void
    {
        $request = new PendingRawQueryRequestStub;
        $pendingRequest = $this->pendingRequest(new PendingRawQueryConnectorStub, $request);
        $request->withQueryString('changed=after-snapshot');

        $pendingRequest->finalizeUri();

        $this->assertSame('tag=a&tag=b&cursor=a%2fb', $pendingRequest->queryString());
        $this->assertSame('https://api.example.com/users?tag=a&tag=b&cursor=a%2fb', (string) $pendingRequest->uri());
        $this->assertSame([], $pendingRequest->queryParameters());

        $pendingRequest->withQueryString('')->finalizeUri();

        $this->assertSame('https://api.example.com/users', (string) $pendingRequest->uri());
        $this->assertSame('changed=after-snapshot', $request->queryString());
    }

    public function testRawQueryReplacementRetainsArrayAndAuthenticationOverlays(): void
    {
        $request = (new PendingRawQueryRequestStub)
            ->withQueryString('tag=a&tag=b&token=old&token=older')
            ->withQueryParameters(['limit' => 10]);
        $pendingRequest = $this->pendingRequest(new PendingRequestConnectorStub, $request);

        $pendingRequest->authenticate(new QueryAuthenticator('token', 'secret'))->finalizeUri();

        $this->assertSame('tag=a&tag=b&version=1&limit=10&token=secret', $pendingRequest->uri()->getQuery());

        $pendingRequest->withQueryString('cursor=next&token=stale')->finalizeUri();
        $pendingRequest->withQueryParameters(['tag' => 'replacement'])->finalizeUri();

        $this->assertSame('cursor=next&version=1&limit=10&token=secret&tag=replacement', $pendingRequest->uri()->getQuery());
        $this->assertSame(['version' => 1, 'limit' => 10, 'token' => 'secret', 'tag' => 'replacement'], $pendingRequest->queryParameters());
    }

    public function testNullRawQueryKeepsTheOriginalUrlQuery(): void
    {
        $pendingRequest = $this->pendingRequest(new PendingRawQueryConnectorStub, new PendingRequestRequestStub);

        $this->assertNull($pendingRequest->queryString());
        $this->assertSame('base=old', $pendingRequest->uri()->getQuery());
    }

    public function testFullUrlOverrideIsVisibleToAuthenticationAndKeepsQueryOverlays(): void
    {
        $request = (new PendingRequestRequestStub)
            ->withUrl('https://uploads.example.com/files?tag=a&tag=b&token=old')
            ->withQueryParameters(['limit' => 10]);
        $pendingRequest = $this->pendingRequest(new PendingRequestConnectorStub, $request);

        $pendingRequest
            ->authenticate(new CookieAuthenticator('session', 'secret'))
            ->authenticate(new QueryAuthenticator('token', 'secret'))
            ->finalizeUri();

        $this->assertSame('https://uploads.example.com/files?tag=a&tag=b&version=1&limit=10&token=secret', (string) $pendingRequest->uri());
        $this->assertSame('uploads.example.com', $pendingRequest->cookies()[0]['Domain']);
        $this->assertTrue($pendingRequest->cookies()[0]['Secure']);

        $pendingRequest->withQueryString('cursor=next&token=stale')->finalizeUri();

        $this->assertSame('https://uploads.example.com/files?cursor=next&version=1&limit=10&token=secret', (string) $pendingRequest->uri());
    }

    #[DataProvider('invalidFullUrls')]
    public function testFullUrlOverrideRequiresAnAbsoluteHttpUrl(string $url): void
    {
        $pendingRequest = $this->pendingRequest(
            new PendingRequestConnectorStub,
            (new PendingRequestRequestStub)->withUrl($url),
        );

        $this->expectException(PendingRequestException::class);

        $pendingRequest->uri();
    }

    /**
     * Provide URLs that cannot replace the full request URL.
     */
    public static function invalidFullUrls(): array
    {
        return [[''], ['/users'], ['//api.example.com/users'], ['file:///etc/passwd']];
    }

    public function testConnectorAndRequestBodyTypesMustMatch(): void
    {
        $this->expectException(PendingRequestException::class);
        $this->expectExceptionMessage('Connector and request body types must be the same.');

        $this->pendingRequest(new PendingRequestConnectorStub, new PendingRequestStringBodyStub);
    }

    public function testLogicalPsrRequestReusesAnAlreadyPreparedBody(): void
    {
        CountingBodyRepository::$streamCalls = 0;
        $pendingRequest = $this->pendingRequest(
            new PendingRequestConnectorWithoutBodyStub,
            new PendingRequestCountingBodyStub,
        );

        $pendingRequest->finalizeUri()->prepareBody();
        $request = $pendingRequest->createPsrRequest();

        $this->assertSame('prepared', (string) $request->getBody());
        $this->assertSame(1, CountingBodyRepository::$streamCalls);
    }

    public function testRequiresAuthRetainsItsProtectedMessageHook(): void
    {
        $pendingRequest = $this->pendingRequest(
            new PendingRequestConnectorWithoutBodyStub,
            new CustomRequiresAuthRequestStub,
        );

        $this->expectException(MissingAuthenticatorException::class);
        $this->expectExceptionMessage('Custom authentication is required.');

        $pendingRequest->bootPlugins();
    }

    public function testAuthenticatorsReplaceLogicalHeadersRegardlessOfCase(): void
    {
        $pendingRequest = $this->pendingRequest(
            new PendingRequestConnectorWithoutBodyStub,
            new PendingRequestRequestStub,
        );

        $pendingRequest
            ->withHeader('authorization', 'Bearer old')
            ->authenticate(new TokenAuthenticator('first'))
            ->authenticate(new AccessTokenAuthenticator('second'));

        $this->assertSame(['Authorization' => 'Bearer second'], $pendingRequest->headers());

        $pendingRequest
            ->withHeader('x-api-key', 'old')
            ->authenticate(new HeaderAuthenticator('new', 'X-Api-Key'));

        $this->assertSame([
            'Authorization' => 'Bearer second',
            'X-Api-Key' => 'new',
        ], $pendingRequest->headers());
    }

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        PendingRequestConnectorStub::$bootCalls = 0;
        PendingRequestRequestStub::$bootCalls = 0;
        PendingRequestRequestStub::$middlewareCalls = 0;
    }

    /**
     * Create a pending request with isolated framework dependencies.
     */
    protected function pendingRequest(Connector $connector, Request $request): PendingRequest
    {
        return new PendingRequest(
            $connector,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );
    }
}

class PendingRequestConnectorStub extends Connector
{
    use HasJsonBody;

    public static int $bootCalls = 0;

    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com/v1';
    }

    /**
     * Configure a pending request for this resource.
     */
    public function boot(PendingRequest $pendingRequest): void
    {
        ++static::$bootCalls;
    }

    /**
     * Resolve the default headers.
     */
    protected function defaultHeaders(): array
    {
        return ['X-Connector' => 'connector'];
    }

    /**
     * Resolve the default query parameters.
     */
    protected function defaultQuery(): array
    {
        return ['version' => 1];
    }

    /**
     * Resolve the default request options.
     */
    protected function defaultOptions(): array
    {
        return ['allow_redirects' => ['strict' => true]];
    }

    /**
     * Resolve the default JSON body.
     */
    protected function defaultBody(): array
    {
        return ['connector' => true];
    }
}

class PendingRawQueryConnectorStub extends Connector
{
    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com?base=old';
    }
}

class PendingTemplateConnectorStub extends Connector
{
    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://{tenant}.example.com/v1';
    }
}

class PendingTemplateRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users/{id}';
    }
}

class PendingRawQueryRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users?endpoint=old';
    }

    /**
     * Resolve the default raw query string override.
     */
    protected function defaultQueryString(): ?string
    {
        return 'tag=a&tag=b&cursor=a%2fb';
    }
}

class PendingRequestRequestStub extends Request
{
    use HasJsonBody;

    public static int $bootCalls = 0;

    public static int $middlewareCalls = 0;

    protected Method $method = Method::POST;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }

    /**
     * Configure a pending request for this resource.
     */
    public function boot(PendingRequest $pendingRequest): void
    {
        ++static::$bootCalls;
    }
}

class PendingRequestStringBodyStub extends Request
{
    use HasStringBody;

    protected Method $method = Method::POST;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }
}

class PendingRequestConnectorWithoutBodyStub extends Connector
{
    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }
}

class PendingRequestCountingBodyStub extends Request
{
    protected Method $method = Method::POST;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }

    /**
     * Resolve the default body repository.
     */
    protected function defaultBodyRepository(): ?BodyRepository
    {
        return new CountingBodyRepository('prepared');
    }
}

class CountingBodyRepository extends StringBodyRepository
{
    public static int $streamCalls = 0;

    /**
     * Convert the body repository into a stream.
     */
    public function toStream(): StreamInterface
    {
        ++static::$streamCalls;

        return parent::toStream();
    }
}

class CustomRequiresAuthRequestStub extends Request
{
    use RequiresAuth;

    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }

    /**
     * Get the missing authenticator message.
     */
    protected function getRequiresAuthMessage(PendingRequest $pendingRequest): string
    {
        return 'Custom authentication is required.';
    }
}
