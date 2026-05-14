<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session\Middleware;

use Hypervel\Http\Request;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;

class StartSessionTest extends TestCase
{
    public function testResolveSessionCookieConfigReturnsDefaults(): void
    {
        $middleware = $this->createStartSessionMock();

        $config = $this->invokeResolveSessionCookieConfig($middleware, Request::create('/'), []);

        $this->assertSame('/', $config['path']);
        $this->assertSame('', $config['domain']);
        $this->assertNull($config['secure']);
        $this->assertTrue($config['http_only']);
        $this->assertNull($config['same_site']);
        $this->assertFalse($config['partitioned']);
    }

    public function testResolveSessionCookieConfigReturnsConfiguredValues(): void
    {
        $middleware = $this->createStartSessionMock();

        $config = $this->invokeResolveSessionCookieConfig($middleware, Request::create('/'), [
            'path' => '/app',
            'domain' => '.example.com',
            'secure' => true,
            'http_only' => false,
            'same_site' => 'strict',
            'partitioned' => true,
        ]);

        $this->assertSame('/app', $config['path']);
        $this->assertSame('.example.com', $config['domain']);
        $this->assertTrue($config['secure']);
        $this->assertFalse($config['http_only']);
        $this->assertSame('strict', $config['same_site']);
        $this->assertTrue($config['partitioned']);
    }

    public function testSessionCookieConfigCanBeConfiguredUsingCallback(): void
    {
        $middleware = $this->createStartSessionMock();

        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['domain'] = '.custom.example.com';

            return $cookie;
        });

        $config = $this->invokeResolveSessionCookieConfig($middleware, Request::create('/'), [
            'path' => '/',
            'domain' => '.example.com',
        ]);

        $this->assertSame('.custom.example.com', $config['domain']);
        $this->assertSame('/', $config['path']);
    }

    public function testSessionCookieConfigCallbacksReceiveRequest(): void
    {
        $middleware = $this->createStartSessionMock();

        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['domain'] = '.' . $request->getHost();

            return $cookie;
        });

        $config = $this->invokeResolveSessionCookieConfig(
            $middleware,
            Request::create('https://tenant.example.com'),
            []
        );

        $this->assertSame('.tenant.example.com', $config['domain']);
    }

    public function testSessionCookieConfigCallbacksComposeInRegistrationOrder(): void
    {
        $middleware = $this->createStartSessionMock();

        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['same_site'] = 'strict';
            $cookie['domain'] = '.first.example.com';

            return $cookie;
        });
        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['same_site'] = 'lax';

            return $cookie;
        });

        $config = $this->invokeResolveSessionCookieConfig($middleware, Request::create('/'), []);

        $this->assertSame('.first.example.com', $config['domain']);
        $this->assertSame('lax', $config['same_site']);
    }

    public function testFlushStateClearsSessionCookieCallbacks(): void
    {
        $middleware = $this->createStartSessionMock();

        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['domain'] = '.custom.example.com';

            return $cookie;
        });

        $this->assertSame(
            '.custom.example.com',
            $this->invokeResolveSessionCookieConfig($middleware, Request::create('/'), [])['domain']
        );

        StartSession::flushState();

        $this->assertSame('', $this->invokeResolveSessionCookieConfig($middleware, Request::create('/'), [])['domain']);
    }

    private function createStartSessionMock(): StartSession
    {
        return new TestStartSession;
    }

    private function invokeResolveSessionCookieConfig(StartSession $middleware, Request $request, array $config): array
    {
        return (new ClassInvoker($middleware))->resolveSessionCookieConfig($request, $config);
    }
}

class TestStartSession extends StartSession
{
    public function __construct()
    {
        // Skip parent constructor for testing.
    }
}
