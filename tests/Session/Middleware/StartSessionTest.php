<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session\Middleware;

use Closure;
use Hypervel\Cache\Lock;
use Hypervel\Contracts\Cache\Factory as CacheFactoryContract;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Session\Session;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Session\SessionManager;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StartSessionTest extends TestCase
{
    public function testResolveSessionCookieConfigReturnsDefaults(): void
    {
        $middleware = $this->createStartSessionMock();

        $config = $this->invokeResolveSessionCookieConfig(
            $middleware,
            Request::create('/'),
            $this->defaultCookieConfig(),
        );

        $this->assertSame('/', $config['path']);
        $this->assertNull($config['domain']);
        $this->assertNull($config['secure']);
        $this->assertTrue($config['http_only']);
        $this->assertNull($config['same_site']);
        $this->assertFalse($config['partitioned']);
    }

    public function testResolveSessionCookieConfigReturnsConfiguredValues(): void
    {
        $middleware = $this->createStartSessionMock();

        $config = $this->invokeResolveSessionCookieConfig($middleware, Request::create('/'), [
            ...$this->defaultCookieConfig(),
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
            ...$this->defaultCookieConfig(),
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
            $this->defaultCookieConfig(),
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

        $config = $this->invokeResolveSessionCookieConfig(
            $middleware,
            Request::create('/'),
            $this->defaultCookieConfig(),
        );

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
            $this->invokeResolveSessionCookieConfig(
                $middleware,
                Request::create('/'),
                $this->defaultCookieConfig(),
            )['domain']
        );

        StartSession::flushState();

        $this->assertNull($this->invokeResolveSessionCookieConfig(
            $middleware,
            Request::create('/'),
            $this->defaultCookieConfig(),
        )['domain']);
    }

    public function testFailedSessionStartupDoesNotRegisterPersistenceRetry(): void
    {
        $request = Request::create('/');
        $failure = new RuntimeException('read failure');
        $manager = m::mock(SessionManager::class);
        $cache = m::mock(CacheFactoryContract::class);
        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $session = m::mock(Session::class);
        $middleware = new StartSession($manager, $cache, $exceptionHandler);

        $session->shouldReceive('setRequestOnHandler')->once()->with($request);
        $session->shouldReceive('start')->once()->andThrow($failure);
        $exceptionHandler->shouldNotReceive('afterResponse');

        try {
            (new ClassInvoker($middleware))->handleStatefulRequest(
                $request,
                $session,
                fn () => $this->fail('The request pipeline should not run after session startup fails.'),
            );

            $this->fail('Expected session startup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertFalse($request->hasSession());
    }

    public function testFailureAfterSuccessfulStartupRegistersPersistenceRetry(): void
    {
        $request = Request::create('/');
        $failure = new RuntimeException('route failure');
        $manager = m::mock(SessionManager::class);
        $cache = m::mock(CacheFactoryContract::class);
        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $session = m::mock(Session::class);
        $middleware = new StartSession($manager, $cache, $exceptionHandler);
        $afterResponse = null;

        $session->shouldReceive('setRequestOnHandler')->once()->with($request);
        $session->shouldReceive('start')->once()->andReturnTrue();
        $manager->shouldReceive('getSessionConfig')->once()->andReturn([
            'lottery' => [0, 1],
        ]);
        $exceptionHandler->shouldReceive('afterResponse')->once()->andReturnUsing(
            function (callable $callback) use (&$afterResponse): void {
                $afterResponse = $callback;
            }
        );
        $manager->shouldReceive('driver')->once()->andReturn($session);
        $session->shouldReceive('save')->once();

        try {
            (new ClassInvoker($middleware))->handleStatefulRequest(
                $request,
                $session,
                fn () => throw $failure,
            );

            $this->fail('Expected the route failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($request->hasSession());
        $this->assertIsCallable($afterResponse);

        $afterResponse();
    }

    public function testPersistenceRetryFailureDoesNotReplacePrimaryFailure(): void
    {
        $request = Request::create('/');
        $failure = new RuntimeException('write failure');
        $manager = m::mock(SessionManager::class);
        $cache = m::mock(CacheFactoryContract::class);
        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $session = m::mock(Session::class);
        $middleware = new StartSession($manager, $cache, $exceptionHandler);
        $afterResponse = null;

        $session->shouldReceive('setRequestOnHandler')->once()->with($request);
        $session->shouldReceive('start')->once()->andReturnTrue();
        $manager->shouldReceive('getSessionConfig')->twice()->andReturn([
            'lottery' => [0, 1],
            'driver' => null,
        ]);
        $manager->shouldReceive('driver')->twice()->andReturn($session);
        $session->shouldReceive('save')->twice()->andThrow($failure);
        $exceptionHandler->shouldReceive('afterResponse')->once()->andReturnUsing(
            function (callable $callback) use (&$afterResponse): void {
                $afterResponse = $callback;
            }
        );

        try {
            (new ClassInvoker($middleware))->handleStatefulRequest(
                $request,
                $session,
                fn () => new Response,
            );

            $this->fail('Expected session persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertIsCallable($afterResponse);

        $afterResponse();
        $this->addToAssertionCount(1);
    }

    public function testBlockingPreservesRequestFailureWhenReleaseAlsoFails(): void
    {
        $request = Request::create('/');
        $route = (new Route('GET', '/', fn () => new Response))->block(10, 0);
        $request->setRouteResolver(fn (): Route => $route);
        $manager = m::mock(SessionManager::class);
        $cache = m::mock(CacheFactoryContract::class);
        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $session = m::mock(Session::class);
        $store = m::mock(Repository::class, LockProvider::class);
        $lock = new StartSessionFailingReleaseLock;
        $middleware = new StartSessionThrowingMiddleware($manager, $cache, $exceptionHandler);

        $manager->shouldReceive('blockDriver')->once()->andReturn('array');
        $cache->shouldReceive('store')->once()->with('array')->andReturn($store);
        $session->shouldReceive('getId')->once()->andReturn('session-id');
        $store->shouldReceive('lock')->once()->with('session:session-id', 10)->andReturn($lock);

        try {
            (new ClassInvoker($middleware))->handleRequestWhileBlocking(
                $request,
                $session,
                fn () => new Response,
            );

            $this->fail('Expected the request pipeline to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('request failure', $exception->getMessage());
        }

        $this->assertTrue($lock->released);
    }

    public function testBlockingTimeoutDoesNotReleaseUnacquiredLock(): void
    {
        $request = Request::create('/');
        $route = (new Route('GET', '/', fn () => new Response))->block(10, 0);
        $request->setRouteResolver(fn (): Route => $route);
        $manager = m::mock(SessionManager::class);
        $cache = m::mock(CacheFactoryContract::class);
        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $session = m::mock(Session::class);
        $store = m::mock(Repository::class, LockProvider::class);
        $lock = new StartSessionUnacquirableLock;
        $middleware = new StartSession($manager, $cache, $exceptionHandler);

        $manager->shouldReceive('blockDriver')->once()->andReturn('array');
        $cache->shouldReceive('store')->once()->with('array')->andReturn($store);
        $session->shouldReceive('getId')->once()->andReturn('session-id');
        $store->shouldReceive('lock')->once()->with('session:session-id', 10)->andReturn($lock);

        try {
            (new ClassInvoker($middleware))->handleRequestWhileBlocking(
                $request,
                $session,
                fn () => $this->fail('The request pipeline should not run without the lock.'),
            );

            $this->fail('Expected lock acquisition to time out.');
        } catch (LockTimeoutException) {
            $this->assertFalse($lock->released);
        }
    }

    private function createStartSessionMock(): StartSession
    {
        return new TestStartSession;
    }

    private function invokeResolveSessionCookieConfig(StartSession $middleware, Request $request, array $config): array
    {
        return (new ClassInvoker($middleware))->resolveSessionCookieConfig($request, $config);
    }

    /**
     * @return array{path: string, domain: ?string, secure: ?bool, http_only: bool, same_site: ?string, partitioned: bool}
     */
    private function defaultCookieConfig(): array
    {
        return [
            'path' => '/',
            'domain' => null,
            'secure' => null,
            'http_only' => true,
            'same_site' => null,
            'partitioned' => false,
        ];
    }
}

class TestStartSession extends StartSession
{
    public function __construct()
    {
        // Skip parent constructor for testing.
    }
}

class StartSessionThrowingMiddleware extends StartSession
{
    protected function handleStatefulRequest(Request $request, Session $session, Closure $next): Response
    {
        throw new RuntimeException('request failure');
    }
}

class StartSessionFailingReleaseLock extends Lock
{
    public bool $released = false;

    public function __construct()
    {
        parent::__construct('session', 10, 'owner');
    }

    public function acquire(): bool
    {
        return true;
    }

    public function release(): bool
    {
        $this->released = true;

        throw new RuntimeException('release failure');
    }

    public function forceRelease(): void
    {
    }

    protected function getCurrentOwner(): ?string
    {
        return $this->owner;
    }
}

class StartSessionUnacquirableLock extends Lock
{
    public bool $released = false;

    public function __construct()
    {
        parent::__construct('session', 10, 'owner');
    }

    public function acquire(): bool
    {
        return false;
    }

    public function release(): bool
    {
        return $this->released = true;
    }

    public function forceRelease(): void
    {
    }

    protected function getCurrentOwner(): ?string
    {
        return null;
    }
}
