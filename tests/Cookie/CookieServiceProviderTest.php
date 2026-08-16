<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cookie;

use Hypervel\Config\Repository;
use Hypervel\Cookie\CookieJar;
use Hypervel\Cookie\CookieServiceProvider;
use Hypervel\Cookie\Middleware\AddQueuedCookiesToResponse;
use Hypervel\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\Session\CookieSessionHandler;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class CookieServiceProviderTest extends TestCase
{
    public function testReloadConfigurationUpdatesTheRetainedCookieJar(): void
    {
        $application = new Application;
        $config = new Repository([
            'session' => [
                'path' => '/old',
                'domain' => 'old.test',
                'secure' => false,
                'same_site' => 'lax',
            ],
        ]);
        $application->instance('config', $config);
        $provider = new CookieServiceProvider($application);
        $provider->register();

        $cookie = $application->make('cookie');
        $handler = new CookieSessionHandler($cookie, 10);
        $middleware = new AddQueuedCookiesToResponse($cookie);

        $config->set([
            'session.path' => '/new',
            'session.domain' => 'new.test',
            'session.secure' => true,
            'session.same_site' => 'strict',
        ]);
        $provider->reloadConfiguration();

        $this->assertSame($cookie, $application->make(CookieJar::class));
        $handler->write('session-id', 'payload');
        $response = $middleware->handle(Request::create('/'), static fn (): Response => new Response);
        $queuedCookie = $response->headers->getCookies()[0];

        $this->assertSame('/new', $queuedCookie->getPath());
        $this->assertSame('new.test', $queuedCookie->getDomain());
        $this->assertTrue($queuedCookie->isSecure());
        $this->assertSame('strict', $queuedCookie->getSameSite());
    }

    public function testReloadConfigurationDoesNotResolveAnUnusedCookieJar(): void
    {
        $application = new Application;
        $application->instance('config', new Repository([
            'session' => [
                'path' => '/',
                'domain' => null,
                'secure' => false,
                'same_site' => 'lax',
            ],
        ]));
        $provider = new CookieServiceProvider($application);
        $provider->register();

        $provider->reloadConfiguration();

        $this->assertFalse($application->resolved('cookie'));
    }
}
