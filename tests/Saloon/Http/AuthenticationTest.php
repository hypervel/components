<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Auth\CookieAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthenticationTest extends TestCase
{
    #[DataProvider('cookieDomains')]
    public function testCookieDomainIsInferredOrExplicit(?string $domain, string $expected, string $scheme): void
    {
        $pendingRequest = new PendingRequest(
            new CookieAuthConnectorStub($scheme . '://api.example.com'),
            (new CookieAuthRequestStub)->authenticate(new CookieAuthenticator('session', 'secret', $domain)),
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );

        $pendingRequest->applyAuthentication();

        $this->assertCount(1, $pendingRequest->cookies());
        $cookie = $pendingRequest->cookies()[0];
        $this->assertSame('session', $cookie['Name']);
        $this->assertSame('secret', $cookie['Value']);
        $this->assertSame($expected, $cookie['Domain']);
        $this->assertSame($domain === null, $cookie['HostOnly'] ?? false);
        $this->assertSame($scheme === 'https', $cookie['Secure']);
        $this->assertTrue($cookie['Discard']);
    }

    /**
     * Provide inferred and explicitly scoped cookie domains.
     */
    public static function cookieDomains(): array
    {
        return [
            'inferred HTTPS' => [null, 'api.example.com', 'https'],
            'explicit HTTPS' => ['.example.com', '.example.com', 'https'],
            'inferred HTTP' => [null, 'api.example.com', 'http'],
            'explicit HTTP' => ['.example.com', '.example.com', 'http'],
        ];
    }

    public function testAnEmptyExplicitCookieDomainIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The cookie domain cannot be empty.');

        new CookieAuthenticator('session', 'secret', '');
    }

    public function testReplacementAuthenticationReachesTheTransportWithoutAccumulatingAcrossRetries(): void
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $cookies = [];
        $http->fake(function (HttpRequest $request, array $options) use (&$cookies) {
            $cookies[] = $options['cookies']->toArray();

            return Factory::response('', count($cookies) === 1 ? 500 : 200);
        });
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')->with('saloon.connection.name')->andReturn('saloon');
        $manager = new SaloonManager(
            new Sender($http, $config),
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
            $config,
            new Dispatcher,
        );
        $request = (new CookieAuthRequestStub)
            ->authenticate(new CookieAuthenticator('session', 'original'))
            ->authenticate(new CookieAuthenticator('session', 'request'))
            ->retry(2);
        $pendingCookies = [];
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest) use (&$pendingCookies): void {
            $pendingRequest->authenticate(new CookieAuthenticator('session', 'replacement'));
            $pendingCookies[] = $pendingRequest->cookies();
        });

        $response = $manager->send(new CookieAuthConnectorStub, $request);

        $this->assertSame(200, $response->status());
        $this->assertCount(2, $cookies);
        foreach ($cookies as $attemptCookies) {
            $this->assertCount(1, $attemptCookies);
            $this->assertSame('session', $attemptCookies[0]['Name']);
            $this->assertSame('replacement', $attemptCookies[0]['Value']);
            $this->assertSame('api.example.com', $attemptCookies[0]['Domain']);
            $this->assertTrue($attemptCookies[0]['HostOnly']);
            $this->assertTrue($attemptCookies[0]['Secure']);
            $this->assertTrue($attemptCookies[0]['Discard']);
        }
        $this->assertSame($pendingCookies[0], $pendingCookies[1]);
        $this->assertCount(2, $pendingCookies[0]);
        $this->assertSame([], $request->cookies());
    }
}

class CookieAuthConnectorStub extends Connector
{
    /**
     * Set the API base URL.
     */
    public function __construct(protected string $baseUrl = 'https://api.example.com')
    {
    }

    /**
     * Resolve the API base URL.
     */
    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }
}

class CookieAuthRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }
}
