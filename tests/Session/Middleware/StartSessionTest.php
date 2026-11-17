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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StartSessionTest extends TestCase
{
    use VerifiesDoubles;

    public function testResolveCookieConfigReturnsDefaults(): void
    {
        $config = StartSession::resolveCookieConfig(
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

    public function testResolveCookieConfigReturnsConfiguredValues(): void
    {
        $config = StartSession::resolveCookieConfig(Request::create('/'), [
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
        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['domain'] = '.custom.example.com';

            return $cookie;
        });

        $config = StartSession::resolveCookieConfig(Request::create('/'), [
            ...$this->defaultCookieConfig(),
            'domain' => '.example.com',
        ]);

        $this->assertSame('.custom.example.com', $config['domain']);
        $this->assertSame('/', $config['path']);
    }

    public function testSessionCookieConfigCallbacksReceiveRequest(): void
    {
        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['domain'] = '.' . $request->getHost();

            return $cookie;
        });

        $config = StartSession::resolveCookieConfig(
            Request::create('https://tenant.example.com'),
            $this->defaultCookieConfig(),
        );

        $this->assertSame('.tenant.example.com', $config['domain']);
    }

    public function testSessionCookieConfigCallbacksComposeInRegistrationOrder(): void
    {
        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['same_site'] = 'strict';
            $cookie['domain'] = '.first.example.com';

            return $cookie;
        });
        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['same_site'] = 'lax';

            return $cookie;
        });

        $config = StartSession::resolveCookieConfig(
            Request::create('/'),
            $this->defaultCookieConfig(),
        );

        $this->assertSame('.first.example.com', $config['domain']);
        $this->assertSame('lax', $config['same_site']);
    }

    public function testFlushStateClearsSessionCookieCallbacks(): void
    {
        StartSession::configureSessionCookieUsing(function (Request $request, array $cookie): array {
            $cookie['domain'] = '.custom.example.com';

            return $cookie;
        });

        $this->assertSame(
            '.custom.example.com',
            StartSession::resolveCookieConfig(
                Request::create('/'),
                $this->defaultCookieConfig(),
            )['domain']
        );

        StartSession::flushState();

        $this->assertNull(StartSession::resolveCookieConfig(
            Request::create('/'),
            $this->defaultCookieConfig(),
        )['domain']);
    }

    public function testFailedSessionStartupDoesNotRegisterPersistenceRetry(): void
    {
        $request = Request::create('/');
        $failure = new RuntimeException('read failure');
        $manager = Double::for(SessionManager::class)->strict();
        $cache = Double::for(CacheFactoryContract::class)->strict();
        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $session = Double::for(Session::class)->strict();
        $middleware = new StartSession($manager, $cache, $exceptionHandler);

        $session->expects('setRequestOnHandler')->with($request);
        $session->expects('start')->throws($failure);
        $exceptionHandler->expects('afterResponse')->never();

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
        $manager = Double::for(SessionManager::class)->strict();
        $cache = Double::for(CacheFactoryContract::class)->strict();
        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $session = Double::for(Session::class)->strict();
        $middleware = new StartSession($manager, $cache, $exceptionHandler);
        $afterResponse = null;

        $session->expects('setRequestOnHandler')->with($request);
        $session->expects('start')->returns(true);
        $session->expects('isReadOnly')->returns(false);
        $manager->expects('getSessionConfig')->returns([
            'lottery' => [0, 1],
        ]);
        $exceptionHandler->expects('afterResponse')->with(Argument::capture($afterResponse));
        $manager->expects('driver')->returns($session);
        $session->expects('save');

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
        $manager = Double::for(SessionManager::class)->strict();
        $cache = Double::for(CacheFactoryContract::class)->strict();
        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $session = Double::for(Session::class)->strict();
        $middleware = new StartSession($manager, $cache, $exceptionHandler);
        $afterResponse = null;

        $session->expects('setRequestOnHandler')->with($request);
        $session->expects('start')->returns(true);
        $session->expects('isReadOnly')->times(2)->returns(false);
        $manager->expects('getSessionConfig')->times(2)->returns([
            'lottery' => [0, 1],
            'driver' => null,
        ]);
        $manager->expects('driver')->times(2)->returns($session);
        $session->expects('save')->times(2)->throws($failure);
        $exceptionHandler->expects('afterResponse')->with(Argument::capture($afterResponse));

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
    }

    public function testBlockingPreservesRequestFailureWhenReleaseAlsoFails(): void
    {
        $request = Request::create('/');
        $route = (new Route('GET', '/', fn () => new Response))->block(10, 0);
        $request->setRouteResolver(fn (): Route => $route);
        $manager = Double::for(SessionManager::class)->strict();
        $cache = Double::for(CacheFactoryContract::class)->strict();
        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $session = Double::for(Session::class)->strict();
        $store = Double::for(Repository::class, LockProvider::class)->strict();
        $lock = new StartSessionFailingReleaseLock;
        $middleware = new StartSessionThrowingMiddleware($manager, $cache, $exceptionHandler);

        $manager->expects('blockDriver')->returns('array');
        $cache->expects('store')->with('array')->returns($store);
        $session->expects('getId')->returns('session-id');
        $store->expects('lock')->with('session:session-id', 10)->returns($lock);

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

    public function testNullBlockStoreUsesTheDefaultCacheStore(): void
    {
        $request = Request::create('/');
        $request->setRouteResolver(fn (): Route => (new Route('GET', '/', fn () => new Response))->block(10, 0));
        $manager = Double::for(SessionManager::class)->strict();
        $cache = Double::for(CacheFactoryContract::class)->strict();
        $session = Double::for(Session::class)->strict();
        $store = Double::for(Repository::class, LockProvider::class)->strict();
        $lock = Double::for(Lock::class)->strict();
        $middleware = new StartSessionRespondingMiddleware(
            $manager,
            $cache,
            Double::for(ExceptionHandlerContract::class)->strict(),
        );

        $manager->expects('blockDriver')->returns(null);
        $cache->expects('store')->with(null)->returns($store);
        $session->expects('getId')->returns('session-id');
        $store->expects('lock')->with('session:session-id', 10)->returns($lock);
        $lock->expects('betweenBlockedAttemptsSleepFor')->with(50);
        $lock->expects('block')
            ->with(0, Argument::type(Closure::class))
            ->resolves(fn (int $seconds, Closure $callback): Response => $callback());

        $response = (new ClassInvoker($middleware))->handleRequestWhileBlocking(
            $request,
            $session,
            fn () => new Response,
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testBlockingTimeoutDoesNotReleaseUnacquiredLock(): void
    {
        $request = Request::create('/');
        $route = (new Route('GET', '/', fn () => new Response))->block(10, 0);
        $request->setRouteResolver(fn (): Route => $route);
        $manager = Double::for(SessionManager::class)->strict();
        $cache = Double::for(CacheFactoryContract::class)->strict();
        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $session = Double::for(Session::class)->strict();
        $store = Double::for(Repository::class, LockProvider::class)->strict();
        $lock = new StartSessionUnacquirableLock;
        $middleware = new StartSession($manager, $cache, $exceptionHandler);

        $manager->expects('blockDriver')->returns('array');
        $cache->expects('store')->with('array')->returns($store);
        $session->expects('getId')->returns('session-id');
        $store->expects('lock')->with('session:session-id', 10)->returns($lock);

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

    /**
     * Provide the default session cookie settings.
     *
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

class StartSessionThrowingMiddleware extends StartSession
{
    /**
     * Fail the request with the test exception.
     */
    protected function handleStatefulRequest(Request $request, Session $session, Closure $next): Response
    {
        throw new RuntimeException('request failure');
    }
}

class StartSessionRespondingMiddleware extends StartSession
{
    /**
     * Pass the request to the next handler.
     */
    protected function handleStatefulRequest(Request $request, Session $session, Closure $next): Response
    {
        return $next($request);
    }
}

class StartSessionFailingReleaseLock extends Lock
{
    public bool $released = false;

    /**
     * Create the session lock fixture.
     */
    public function __construct()
    {
        parent::__construct('session', 10, 'owner');
    }

    /**
     * Acquire the test lock.
     */
    public function acquire(): bool
    {
        return true;
    }

    /**
     * Record the release attempt and fail.
     */
    public function release(): bool
    {
        $this->released = true;

        throw new RuntimeException('release failure');
    }

    /**
     * Leave forced release inactive for this fixture.
     */
    public function forceRelease(): void
    {
    }

    /**
     * Return the test lock owner.
     */
    protected function getCurrentOwner(): ?string
    {
        return $this->owner;
    }
}

class StartSessionUnacquirableLock extends Lock
{
    public bool $released = false;

    /**
     * Create the session lock fixture.
     */
    public function __construct()
    {
        parent::__construct('session', 10, 'owner');
    }

    /**
     * Refuse to acquire the test lock.
     */
    public function acquire(): bool
    {
        return false;
    }

    /**
     * Record the release attempt.
     */
    public function release(): bool
    {
        return $this->released = true;
    }

    /**
     * Leave forced release inactive for this fixture.
     */
    public function forceRelease(): void
    {
    }

    /**
     * Return the test lock owner.
     */
    protected function getCurrentOwner(): ?string
    {
        return null;
    }
}
