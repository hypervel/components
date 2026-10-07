<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use GuzzleHttp\Cookie\SetCookie;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\InvalidResponseClassException;
use Hypervel\Saloon\Exceptions\NoMockResponseFoundException;
use Hypervel\Saloon\Exceptions\PendingRequestException;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\CustomBaseUrlConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\CustomResponseConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\CustomEndpointRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\CustomResponseConnectorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\DefaultEndpointRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\InvalidResponseClass;
use Hypervel\Tests\Saloon\Fixtures\Requests\MissingMethodRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequestWithCustomResponse;
use Hypervel\Tests\Saloon\Fixtures\Responses\CustomResponse;
use Hypervel\Tests\Saloon\Fixtures\Responses\UserResponse;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class RequestTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    // Upstream asserts the pending request's mock client. Hypervel selects the mock client when the request is
    // sent, so these cases check the request's client and the response that sending produces.
    public function testIfYouDontPassInAMockClientToTheSaloonRequestItWillNotBeInMockingMode(): void
    {
        Http::fake();

        $request = new UserRequest;

        $this->assertFalse($request->hasMockClient());
        $this->assertFalse((new TestConnector)->send($request)->isMocked());
    }

    public function testYouCanPassAMockClientToTheSaloonRequestAndItWillBeInMockMode(): void
    {
        $request = new UserRequest;
        $mockClient = new MockClient([MockResponse::make([])]);

        $request->withMockClient($mockClient);

        $this->assertTrue($request->hasMockClient());
        $this->assertSame($mockClient, $request->mockClient());
        $this->assertTrue((new TestConnector)->send($request)->isMocked());
    }

    public function testYouCantSendARequestWithAMockClientWithoutAnyResponses(): void
    {
        $mockClient = new MockClient;
        $request = new UserRequest;

        $this->expectException(NoMockResponseFoundException::class);

        (new TestConnector)->send($request, $mockClient);
    }

    public function testSaloonWorksWithACustomResponseClassInConnector(): void
    {
        $connector = new CustomResponseConnector;

        // Hypervel passes the base response so the class may depend on it.
        $this->assertSame(CustomResponse::class, $connector->resolveResponseClass($this->response()));
    }

    public function testSaloonCanHandleWithCustomResponseInConnector(): void
    {
        $request = new CustomResponseConnectorRequest;
        $response = (new CustomResponseConnector)->send($request, new MockClient([MockResponse::make([])]));

        $this->assertInstanceOf(CustomResponse::class, $response);
    }

    public function testSaloonCanHandleWithCustomResponseInRequest(): void
    {
        $request = new UserRequestWithCustomResponse;

        $this->assertSame(UserResponse::class, $request->resolveResponseClass($this->response()));
    }

    public function testSaloonThrowsAnExceptionIfTheCustomResponseIsNotAResponseClass(): void
    {
        $invalidConnectorClassRequest = new InvalidResponseClass;

        $this->expectException(InvalidResponseClassException::class);

        $connector = new TestConnector;

        $connector->send($invalidConnectorClassRequest, new MockClient([
            InvalidResponseClass::class => MockResponse::make([], 200),
        ]));
    }

    public function testDefineEndpointMethodMayBeBlankInRequestClassToUseTheBaseUrl(): void
    {
        $pendingRequest = (new TestConnector)->createPendingRequest(new DefaultEndpointRequest);

        $this->assertSame(TestConnector::API_URL, (string) $pendingRequest->uri());
    }

    public function testARequestClassCanBeInstantiatedUsingTheMakeMethod(): void
    {
        $requestA = UserRequest::make();

        $this->assertInstanceOf(UserRequest::class, $requestA);
        $this->assertNull($requestA->userId);
        $this->assertNull($requestA->groupId);

        $requestB = UserRequest::make(1, 2);

        $this->assertInstanceOf(UserRequest::class, $requestB);
        $this->assertSame(1, $requestB->userId);
        $this->assertSame(2, $requestB->groupId);

        $this->assertSame('users/1', RequiredArgumentRequestStub::make('users/1')->resolveEndpoint());
    }

    #[DataProvider('urlsToJoin')]
    public function testYouCanJoinVariousUrlsTogether(string $baseUrl, string $endpoint, string $expected): void
    {
        $connector = new CustomBaseUrlConnector($baseUrl);
        $request = new CustomEndpointRequest;

        $request->setEndpoint($endpoint);

        $this->assertSame($expected, (string) $connector->createPendingRequest($request)->uri());
    }

    /**
     * Get the base URLs and endpoints to join.
     *
     * @return array<int, array{string, string, string}>
     */
    public static function urlsToJoin(): array
    {
        return [
            ['https://google.com', '/search', 'https://google.com/search'],
            ['https://google.com', 'search', 'https://google.com/search'],
            ['https://google.com/', '/search', 'https://google.com/search'],
            ['https://google.com/', 'search', 'https://google.com/search'],
            ['https://google.com//', '//search', 'https://google.com/search'],
        ];
    }

    public function testARelativeEndpointCannotBeJoinedToAnEmptyBaseUrl(): void
    {
        // Upstream's last join case returns the unsendable relative URL "/google.com/search".
        $request = (new CustomEndpointRequest)->setEndpoint('google.com/search');
        $pendingRequest = (new CustomBaseUrlConnector)->createPendingRequest($request);

        $this->expectException(PendingRequestException::class);
        $this->expectExceptionMessageIs('A request without a connector base URL must use an absolute HTTP or HTTPS endpoint.');

        $pendingRequest->uri();
    }

    public function testItThrowsAnExceptionIfYouForgetToAddAMethod(): void
    {
        $connector = new TestConnector;
        $request = new MissingMethodRequest;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Your request is missing an HTTP method. Add a method property such as [protected Method $method = Method::GET].');

        $connector->send($request);
    }

    public function testContainerResolutionAlwaysReturnsAFreshRequestWithItsDependencies(): void
    {
        $first = $this->app->make(ContainerRequestStub::class);
        $second = $this->app->make(ContainerRequestStub::class);

        $this->assertNotSame($first, $second);
        $this->assertSame($this->app->make('config'), $first->config);
    }

    public function testContainerResolutionPassesRequestParameters(): void
    {
        $request = $this->app->make(RequiredArgumentRequestStub::class, ['endpoint' => 'users/1']);

        $this->assertSame('users/1', $request->resolveEndpoint());
    }

    #[DataProvider('invalidCookies')]
    public function testWithCookieRejectsInvalidCookies(array $cookie, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        (new UserRequest)->withCookie(new SetCookie($cookie));
    }

    /**
     * Provide cookies that cannot be sent with a request.
     */
    public static function invalidCookies(): array
    {
        return [
            'null domain' => [
                ['Name' => 'locale', 'Value' => 'en'],
                'An outgoing cookie must have a domain.',
            ],
            'empty domain' => [
                ['Name' => 'locale', 'Value' => 'en', 'Domain' => ''],
                'Invalid cookie: The cookie domain must not be empty',
            ],
            'null value' => [
                ['Name' => 'locale', 'Domain' => 'api.example.com'],
                'Invalid cookie: The cookie value must not be empty',
            ],
        ];
    }

    public function testRequestsAreMacroable(): void
    {
        UserRequest::macro('endpoint', function (): string {
            return $this->resolveEndpoint();
        });

        $this->assertSame('/user', (new UserRequest)->endpoint());
    }

    /**
     * Send a request through a mock client and return its response.
     */
    protected function response(): Response
    {
        return (new TestConnector)->send(new UserRequest, new MockClient([MockResponse::make([])]));
    }
}

class ContainerRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Create a new request instance.
     */
    public function __construct(public readonly Repository $config)
    {
    }

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return 'users';
    }
}

class RequiredArgumentRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Create a new request instance.
     */
    public function __construct(protected string $endpoint)
    {
    }

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return $this->endpoint;
    }
}
