<?php

declare(strict_types=1);

namespace Hypervel\Session\Middleware;

use Closure;
use DateTimeInterface;
use Hypervel\Context\Context;
use Hypervel\Contracts\Cache\Factory as CacheFactoryContract;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Session\Session;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Session\SessionManager;
use Hypervel\Session\Store;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StartSession
{
    /**
     * Create a new session middleware.
     */
    public function __construct(
        protected SessionManager $manager,
        protected CacheFactoryContract $cache,
        protected ExceptionHandlerContract $exceptionHandler,
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->sessionConfigured()) {
            return $next($request);
        }

        $session = $this->getSession($request);

        if ($this->manager->shouldBlock()
            || ($request->route() instanceof Route && $request->route()->locksFor())) { // @phpstan-ignore instanceof.alwaysTrue
            return $this->handleRequestWhileBlocking($request, $session, $next);
        }

        return $this->handleStatefulRequest($request, $session, $next);
    }

    /**
     * Handle the given request within session state.
     */
    protected function handleRequestWhileBlocking(Request $request, Session $session, Closure $next): Response
    {
        if (! $request->route() instanceof Route) { // @phpstan-ignore instanceof.alwaysTrue
            return $this->handleStatefulRequest($request, $session, $next);
        }

        $lockFor = $request->route()->locksFor()
            ?: $this->manager->defaultRouteBlockLockSeconds();

        /** @var \Hypervel\Contracts\Cache\Repository&LockProvider $store */ // @phpstan-ignore varTag.nativeType
        $store = $this->cache->store($this->manager->blockDriver());
        $lock = $store
            ->lock('session:' . $session->getId(), (int) $lockFor)
            ->betweenBlockedAttemptsSleepFor(50);

        try {
            $lock->block(
                ! is_null($request->route()->waitsFor())
                    ? $request->route()->waitsFor()
                    : $this->manager->defaultRouteBlockWaitSeconds()
            );

            return $this->handleStatefulRequest($request, $session, $next);
        } finally {
            $lock->release();
        }
    }

    /**
     * Handle the given request within session state.
     */
    protected function handleStatefulRequest(Request $request, Session $session, Closure $next): Response
    {
        try {
            // If a session driver has been configured, we will need to start the session here
            // so that the data is ready for an application. Note that the Hypervel sessions
            // do not make use of PHP "native" sessions in any way since they are crappy.
            $request->setLaravelSession(
                $this->startSession($request, $session)
            );

            // Store the session in coroutine Context so that code outside the
            // middleware pipeline (exception handlers, etc.) can access it.
            Context::set(Store::CONTEXT_KEY, $session);

            $this->collectGarbage($session);

            $response = $next($request);

            $this->storeCurrentUrl($request, $session);

            $this->addCookieToResponse($response, $session);

            // Again, if the session has been configured we will need to close out the session
            // so that the attributes may be persisted to some storage medium. We will also
            // add the session identifier cookie to the application response headers now.
            $this->saveSession($request);

            return $response;
        } catch (Throwable $e) {
            $this->exceptionHandler->afterResponse(
                fn () => $this->saveSession($request)
            );

            throw $e;
        }
    }

    /**
     * Start the session for the given request.
     */
    protected function startSession(Request $request, Session $session): Session
    {
        return tap($session, function (Session $session) use ($request) {
            $session->setRequestOnHandler($request);

            $session->start();
        });
    }

    /**
     * Get the session implementation from the manager.
     */
    public function getSession(Request $request): Session
    {
        return tap($this->manager->driver(), function ($session) use ($request) {
            $session->setId($request->cookies->get($session->getName()));
        });
    }

    /**
     * Remove the garbage from the session if necessary.
     */
    protected function collectGarbage(Session $session): void
    {
        $config = $this->manager->getSessionConfig();

        // Here we will see if this request hits the garbage collection lottery by hitting
        // the odds needed to perform garbage collection on any given request. If we do
        // hit it, we'll call this handler to let it delete all the expired sessions.
        if ($this->configHitsLottery($config)) {
            $session->getHandler()->gc($this->getSessionLifetimeInSeconds());
        }
    }

    /**
     * Determine if the configuration odds hit the lottery.
     */
    protected function configHitsLottery(array $config): bool
    {
        return random_int(1, $config['lottery'][1]) <= $config['lottery'][0];
    }

    /**
     * Store the current URL for the request if necessary.
     */
    protected function storeCurrentUrl(Request $request, Session $session): void
    {
        if ($request->isMethod('GET')
            && $request->route() instanceof Route // @phpstan-ignore instanceof.alwaysTrue
            && ! $request->ajax()
            && ! $request->prefetch()
            && ! $request->isPrecognitive()) {
            $session->setPreviousUrl($request->fullUrl());

            if (method_exists($session, 'setPreviousRoute')) {
                $session->setPreviousRoute($request->route()->getName());
            }
        }
    }

    /**
     * Add the session cookie to the application response.
     */
    protected function addCookieToResponse(Response $response, Session $session): void
    {
        if ($this->sessionIsPersistent($config = $this->manager->getSessionConfig())) {
            $cookieConfig = $this->getSessionCookieConfig($config);

            $response->headers->setCookie(new Cookie(
                $session->getName(),
                $session->getId(),
                $this->getCookieExpirationDate(),
                $cookieConfig['path'],
                $cookieConfig['domain'],
                $cookieConfig['secure'],
                $cookieConfig['http_only'],
                false,
                $cookieConfig['same_site'],
                $cookieConfig['partitioned']
            ));
        }
    }

    /**
     * Get the session cookie configuration.
     *
     * Extracted as an extension point so subclasses can provide dynamic
     * cookie settings without duplicating the rest of addCookieToResponse.
     *
     * @return array{path: string, domain: string, secure: bool, http_only: bool, same_site: ?string, partitioned: bool}
     */
    protected function getSessionCookieConfig(array $config): array
    {
        return [
            'path' => $config['path'] ?? '/',
            'domain' => $config['domain'] ?? '',
            'secure' => $config['secure'] ?? false,
            'http_only' => $config['http_only'] ?? true,
            'same_site' => $config['same_site'] ?? null,
            'partitioned' => $config['partitioned'] ?? false,
        ];
    }

    /**
     * Save the session data to storage.
     */
    protected function saveSession(Request $request): void
    {
        if (! $request->isPrecognitive()) {
            $this->manager->driver()->save();
        }
    }

    /**
     * Get the session lifetime in seconds.
     */
    protected function getSessionLifetimeInSeconds(): int
    {
        return ($this->manager->getSessionConfig()['lifetime'] ?? null) * 60;
    }

    /**
     * Get the cookie lifetime in seconds.
     */
    protected function getCookieExpirationDate(): DateTimeInterface|int
    {
        $expiresOnClose = $this->manager->getSessionConfig()['expire_on_close'];

        return $expiresOnClose ? 0 : Date::instance(
            Carbon::now()->addSeconds($this->getSessionLifetimeInSeconds())
        );
    }

    /**
     * Determine if a session driver has been configured.
     */
    protected function sessionConfigured(): bool
    {
        return ! is_null($this->manager->getSessionConfig()['driver'] ?? null);
    }

    /**
     * Determine if the configured session driver is persistent.
     */
    protected function sessionIsPersistent(?array $config = null): bool
    {
        $config = $config ?: $this->manager->getSessionConfig();

        return ! is_null($config['driver'] ?? null);
    }
}
