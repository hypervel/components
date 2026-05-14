<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

class FrontendCookieHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set([
            'session.driver' => 'array',
            'session.http_only' => false,
            'session.same_site' => 'strict',
            'sanctum.stateful' => ['test.com'],
        ]);

        $provider = $this->app->make(SanctumServiceProvider::class);
        $provider->register();
        $provider->boot();
    }

    public function testStatefulFrontendRequestForcesSecureSessionCookieAttributes(): void
    {
        $cookie = $this->sessionCookie($this->handleStatefulFrontendRequest());

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertFalse($this->app->make('config')->get('session.http_only'));
        $this->assertSame('strict', $this->app->make('config')->get('session.same_site'));
    }

    public function testSanctumSessionCookieHonorsApplicationCookieCallbacks(): void
    {
        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['domain'] = '.example.com';

            return $cookie;
        });

        $cookie = $this->sessionCookie($this->handleStatefulFrontendRequest());

        $this->assertSame('.example.com', $cookie->getDomain());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function testNonSanctumSessionCookieDoesNotReceiveSanctumHardening(): void
    {
        $request = Request::create('http://localhost/normal', 'GET');

        $response = $this->app->make(StartSession::class)
            ->handle($request, fn () => new Response('ok'));

        $cookie = $this->sessionCookie($response);

        $this->assertFalse($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
    }

    protected function handleStatefulFrontendRequest(): Response
    {
        $request = Request::create('http://localhost/probe', 'GET', server: [
            'HTTP_ORIGIN' => 'https://test.com',
        ]);

        return (new EnsureFrontendRequestsAreStateful)
            ->handle($request, fn () => new Response('ok'));
    }

    protected function sessionCookie(Response $response): Cookie
    {
        $sessionCookieName = $this->app->make('config')->get('session.cookie');

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $sessionCookieName) {
                return $cookie;
            }
        }

        $this->fail("Session cookie [{$sessionCookieName}] was not set.");
    }
}
