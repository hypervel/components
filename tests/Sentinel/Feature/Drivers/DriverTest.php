<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentinel\Feature\Drivers;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\Sentinel\Drivers\Driver;
use Hypervel\Sentinel\Sentinel;
use Hypervel\Sentinel\SentinelManager;
use Hypervel\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class DriverTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $manager = $app->make(SentinelManager::class);

        $manager->extend('testing', function (ApplicationContract $app): Driver {
            return new class(fn (): ApplicationContract => $app) extends Driver {
                /**
                 * Authorize access for the request.
                 */
                public function authorize(Request $request): bool
                {
                    return $this->authorizeAccessingViaReverseProxies($request);
                }
            };
        });
    }

    public function testItCanAuthorizeLocalRequest(): void
    {
        $request = $this->createRequest([
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        tap(Sentinel::driver('testing'), function (Driver $driver) use ($request): void {
            $this->assertTrue($driver->authorize($request));
        });
    }

    public function testItCanAuthorizeReverseProxyRequest(): void
    {
        $request = $this->createRequest($this->transformHeadersToServerVars([
            'REMOTE_ADDR' => '127.0.0.1',
            'HOST' => 'hypervel.ngrok.io',
            'X-FORWARDED-FOR' => '127.0.0.1',
            'X-FORWARDED-HOST' => 'hypervel.ngrok.io',
            'X-FORWARDED-PROTO' => 'https',
        ]));

        tap(Sentinel::driver('testing'), function (Driver $driver) use ($request): void {
            $this->assertTrue($driver->authorize($request));
        });
    }

    public function testItCanAuthorizeReverseProxyRequestWhenForwardingForPublicIps(): void
    {
        $request = $this->createRequest($this->transformHeadersToServerVars([
            'REMOTE_ADDR' => '127.0.0.1',
            'HOST' => 'hypervel.ngrok.io',
            'X-FORWARDED-FOR' => '202.168.65.217',
            'X-FORWARDED-HOST' => 'hypervel.ngrok.io',
            'X-FORWARDED-PROTO' => 'https',
        ]));

        tap(Sentinel::driver('testing'), function (Driver $driver) use ($request): void {
            $this->assertFalse($driver->authorize($request));
        });
    }

    public function testItCanAuthorizeOrFailReverseProxyRequestWhenForwardingForPublicIps(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $request = $this->createRequest($this->transformHeadersToServerVars([
            'REMOTE_ADDR' => '127.0.0.1',
            'HOST' => 'hypervel.ngrok.io',
            'X-FORWARDED-FOR' => '202.168.65.217',
            'X-FORWARDED-HOST' => 'hypervel.ngrok.io',
            'X-FORWARDED-PROTO' => 'https',
        ]));

        Sentinel::driver('testing')->authorizeOrFail($request);
    }

    // REMOVED: testItCanAuthorizeDockerLocalRequest - Driver::isRunningOnDockerLocally() is not ported, since Docker
    // must not bypass the public-client check. HypervelTest covers the default driver ignoring the marker.

    /**
     * Create a request that trusts forwarded headers from the local proxy.
     */
    private function createRequest(array $server): Request
    {
        $request = Request::create('/', 'GET', [], [], [], $server);

        // Hypervel keeps trusted proxies on the current coroutine's request.
        RequestContext::set($request);
        Request::setTrustedProxies(['127.0.0.1'], SymfonyRequest::HEADER_X_FORWARDED_FOR | SymfonyRequest::HEADER_X_FORWARDED_HOST | SymfonyRequest::HEADER_X_FORWARDED_PORT | SymfonyRequest::HEADER_X_FORWARDED_PROTO);

        return $request;
    }
}
