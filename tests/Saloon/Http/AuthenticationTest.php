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
    public function testCookieDomainIsInferredOrExplicit(?string $domain, string $expected): void
    {
        $pendingRequest = new PendingRequest(
            new CookieAuthConnectorStub,
            (new CookieAuthRequestStub)->authenticate(new CookieAuthenticator('session', 'secret', $domain)),
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );

        $pendingRequest->applyAuthentication();

        $this->assertSame([
            ['cookies' => ['session' => 'secret'], 'domain' => $expected],
        ], $pendingRequest->cookies());
    }

    /**
     * Provide inferred and explicitly scoped cookie domains.
     */
    public static function cookieDomains(): array
    {
        return [[null, 'api.example.com'], ['.example.com', '.example.com']];
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
        $groups = [];
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest) use (&$groups): void {
            $pendingRequest->authenticate(new CookieAuthenticator('session', 'replacement'));
            $groups[] = $pendingRequest->cookies();
        });

        $response = $manager->send(new CookieAuthConnectorStub, $request);

        $this->assertSame(200, $response->status());
        $this->assertCount(2, $cookies);
        foreach ($cookies as $attemptCookies) {
            $this->assertCount(1, $attemptCookies);
            $this->assertSame('session', $attemptCookies[0]['Name']);
            $this->assertSame('replacement', $attemptCookies[0]['Value']);
            $this->assertSame('api.example.com', $attemptCookies[0]['Domain']);
        }
        $this->assertSame($groups[0], $groups[1]);
        $this->assertCount(2, $groups[0]);
        $this->assertSame([], $request->cookies());
    }
}

class CookieAuthConnectorStub extends Connector
{
    /**
     * Resolve the API base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
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
