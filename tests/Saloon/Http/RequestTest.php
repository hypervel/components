<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use ArgumentCountError;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Hypervel\Container\Container;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class RequestTest extends TestCase
{
    public function testContainerResolutionAlwaysReturnsAFreshRequest(): void
    {
        $container = new Container;

        $first = $container->make(ContainerRequestStub::class);
        $second = $container->make(ContainerRequestStub::class);

        $this->assertNotSame($first, $second);
    }

    public function testMakeForwardsRequiredConstructorArguments(): void
    {
        $request = RequiredArgumentRequestStub::make('users/1');

        $this->assertSame('users/1', $request->resolveEndpoint());
    }

    public function testContainerResolutionOfRequiredArgumentRequestFailsNaturally(): void
    {
        $container = new Container;

        $this->expectException(ArgumentCountError::class);

        $container->make(RequiredArgumentRequestStub::class);
    }

    public function testReplaceHeadersMatchesNamesCaseInsensitively(): void
    {
        $request = (new ContainerRequestStub)
            ->withHeaders([
                'Authorization' => 'Bearer old',
                'X-Keep' => 'yes',
            ])
            ->replaceHeaders([
                'authorization' => 'Bearer new',
                'AUTHORIZATION' => 'Bearer newest',
            ]);

        $this->assertSame([
            'X-Keep' => 'yes',
            'AUTHORIZATION' => 'Bearer newest',
        ], $request->headers());
    }

    public function testIncomingHeaderWinsWhenAnExistingCaseVariantFollowsItsExactName(): void
    {
        $request = (new ContainerRequestStub)
            ->withHeaders([
                'Authorization' => 'Bearer stale exact',
                'authorization' => 'Bearer stale variant',
                'X-Keep' => 'yes',
            ])
            ->replaceHeaders([
                'Authorization' => 'Bearer new',
            ]);

        $this->assertSame([
            'X-Keep' => 'yes',
            'Authorization' => 'Bearer new',
        ], $request->headers());
    }

    public function testWithHeadersRemainsAdditive(): void
    {
        $request = (new ContainerRequestStub)
            ->withHeaders(['X-Value' => 'first'])
            ->withHeaders(['X-Value' => 'second']);

        $this->assertSame(['first', 'second'], $request->headers()['X-Value']);
    }

    public function testAcceptReplacesTheExistingAcceptHeader(): void
    {
        $request = (new ContainerRequestStub)
            ->accept('text/plain')
            ->acceptJson();

        $this->assertSame('application/json', $request->headers()['Accept']);
    }

    #[DataProvider('invalidCookies')]
    public function testWithCookieRejectsInvalidCookies(array $cookie, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new ContainerRequestStub)->withCookie(new SetCookie($cookie));
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

    public function testCloneOwnsIndependentInitializedRequestState(): void
    {
        $request = (new ContainerRequestStub)
            ->withHeader('X-Original', 'yes')
            ->withQueryParameters(['page' => 1])
            ->withOptions(['verify' => true])
            ->delay(10)
            ->withCookies(['original' => 'yes'], '.example.test')
            ->retry([10])
            ->withData(['original' => true])
            ->disableCaching();
        $request->middleware()->onRequest(static fn ($pendingRequest) => $pendingRequest, 'original');

        $clone = clone $request;
        $clone
            ->withHeader('X-Clone', 'yes')
            ->withQueryParameters(['page' => 2])
            ->withOptions(['verify' => false])
            ->delay(20)
            ->withCookies(['clone' => 'yes'], 'api.example.test')
            ->retry(3, 20)
            ->withData(['clone' => true])
            ->enableCaching()
            ->invalidateCache();
        $clone->middleware()->onRequest(static fn ($pendingRequest) => $pendingRequest, 'clone');

        $this->assertSame('yes', $request->headers()['X-Original']);
        $this->assertSame('application/json', $request->headers()['Content-Type']);
        $this->assertSame(['page' => 1], $request->queryParameters());
        $this->assertTrue($request->options()['verify']);
        $this->assertSame(10, $request->delayMilliseconds());
        $this->assertCount(1, $request->middleware()->requestPipeline()->pipes());
        $this->assertSame(['original' => true], $request->body());
        $this->assertSame(CookieJar::fromArray(['original' => 'yes'], '.example.test')->toArray(), $request->cookies());
        $this->assertSame([10], $request->retryPolicy()->times);
        $this->assertFalse($request->cachingEnabled());
        $this->assertFalse($request->shouldInvalidateCache());

        $this->assertSame(['original' => true, 'clone' => true], $clone->body());
        $this->assertCount(2, $clone->cookies());
        $this->assertSame(3, $clone->retryPolicy()->times);
        $this->assertTrue($clone->cachingEnabled());
        $this->assertTrue($clone->shouldInvalidateCache());
    }

    public function testRawQueryDefaultsOverridesAndCloneIsolation(): void
    {
        $this->assertNull((new ContainerRequestStub)->queryString());
        $request = new RawQueryRequestStub;
        $this->assertSame('tag=a&tag=b', $request->queryString());
        $request->withQueryString('cursor=a%2Fb')->withQueryParameters(['limit' => 10]);
        $clone = clone $request;

        $this->assertSame($clone, $clone->withQueryString(''));
        $this->assertSame('', $clone->queryString());
        $this->assertSame('cursor=a%2Fb', $request->queryString());
        $this->assertSame(['limit' => 10], $clone->queryParameters());
    }
}

class ContainerRequestStub extends Request
{
    use HasCaching;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'users';
    }
}

class RawQueryRequestStub extends ContainerRequestStub
{
    /**
     * Resolve the default raw query string override.
     */
    protected function defaultQueryString(): ?string
    {
        return 'tag=a&tag=b';
    }
}

class RequiredArgumentRequestStub extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected string $endpoint)
    {
    }

    public function resolveEndpoint(): string
    {
        return $this->endpoint;
    }
}
