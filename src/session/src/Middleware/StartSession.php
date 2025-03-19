<?php

declare(strict_types=1);

namespace Hypervel\Session\Middleware;

use Carbon\Carbon;
use DateTimeInterface;
use Hyperf\Context\Context;
use Hyperf\Contract\SessionInterface;
use Hyperf\HttpServer\Request;
use Hyperf\HttpServer\Router\Dispatched;
use Hypervel\Cache\Contracts\Factory as CacheFactoryContract;
use Hypervel\Cookie\Cookie;
use Hypervel\Session\Contracts\Session;
use Hypervel\Session\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class StartSession implements MiddlewareInterface
{
    /**
     * Create a new session middleware.
     *
     * @param SessionManager $manager the session manager
     * @param CacheFactoryContract $cache the cache factory
     * @param Request $request Hyperf's request proxy
     */
    public function __construct(
        protected SessionManager $manager,
        protected CacheFactoryContract $cache,
        protected Request $request
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $this->sessionConfigured()) {
            return $handler->handle($request);
        }

        $session = $this->getSession();

        $blockingOptions = $this->getBlockingOptions();
        if ($blockingOptions || $this->manager->shouldBlock()) {
            return $this->handleRequestWhileBlocking($request, $session, $handler, $blockingOptions);
        }

        return $this->handleStatefulRequest($request, $session, $handler);
    }

    /**
     * Get the blocking options for the request.
     *
     * @return array{lock?: int, wait?: int}|array{}
     */
    protected function getBlockingOptions(): array
    {
        if (! $dispatched = $this->request->getAttribute(Dispatched::class)) {
            return [];
        }

        return $dispatched->handler->options['block'] ?? [];
    }

    /**
     * Handle the given request within session state.
     */
    protected function handleRequestWhileBlocking(ServerRequestInterface $request, Session $session, RequestHandlerInterface $handler, array $blockingOptions): mixed
    {
        $lockFor = $blockingOptions['lock']
            ?? $this->manager->defaultRouteBlockLockSeconds();
        $waitFor = ($blockingOptions['wait']
            ?? $this->manager->defaultRouteBlockWaitSeconds());

        /* @phpstan-ignore-next-line */
        $lock = $this->cache->store($this->manager->blockDriver())
            ->lock('session:' . $session->getId(), (int) $lockFor)
            ->betweenBlockedAttemptsSleepFor(50);

        try {
            $lock->block((int) $waitFor);

            return $this->handleStatefulRequest($request, $session, $handler);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Handle the given request within session state.
     */
    protected function handleStatefulRequest(ServerRequestInterface $request, Session $session, RequestHandlerInterface $handler): mixed
    {
        // If a session driver has been configured, we will need to start the session here
        // so that the data is ready for an application. Note that the Hypervel sessions
        // do not make use of PHP "native" sessions in any way since they are crappy.
        Context::set(SessionInterface::class, $session);
        $session->start();

        $this->collectGarbage($session);

        $response = $handler->handle($request);

        $this->storeCurrentUrl($session);

        $response = $this->addCookieToResponse($response, $session);

        // Again, if the session has been configured we will need to close out the session
        // so that the attributes may be persisted to some storage medium. We will also
        // add the session identifier cookie to the application response headers now.
        $this->saveSession();

        return $response;
    }

    /**
     * Get the session implementation from the manager.
     */
    public function getSession(): Session
    {
        return tap($this->manager->driver(), function ($session) {
            $session->setId($this->request->cookie($session->getName()));
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
    protected function storeCurrentUrl(Session $session): void
    {
        if ($this->request->isMethod('GET')
            && ! $this->request->header('X-Requested-With') === 'XMLHttpRequest' // is not ajax
            && ! $this->isPrefetch()
        ) {
            $session->setPreviousUrl($this->request->fullUrl());
        }
    }

    /**
     * Determine if the request is prefetch.
     */
    protected function isPrefetch(): bool
    {
        return strcasecmp($this->request->server('HTTP_X_MOZ') ?? '', 'prefetch') === 0
            || strcasecmp($this->request->header('Purpose') ?? '', 'prefetch') === 0
            || strcasecmp($this->request->header('Sec-Purpose') ?? '', 'prefetch') === 0;
    }

    /**
     * Add the session cookie to the application response.
     */
    protected function addCookieToResponse(ResponseInterface $response, Session $session): ResponseInterface
    {
        if (! $this->sessionIsPersistent($config = $this->manager->getSessionConfig())) {
            return $response;
        }

        $cookie = new Cookie(
            $session->getName(),
            $session->getId(),
            $this->getCookieExpirationDate(),
            $config['path'] ?? '/',
            $config['domain'] ?? '',
            $config['secure'] ?? false,
            $config['http_only'] ?? true,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );

        /** @var \Hyperf\HttpMessage\Server\Response $response */
        return $response->withCookie($cookie);
    }

    /**
     * Save the session data to storage.
     */
    protected function saveSession(): void
    {
        $this->manager->driver()->save();
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
        $config = $this->manager->getSessionConfig();

        return $config['expire_on_close']
            ? 0
            : Carbon::now()->addRealMinutes($config['lifetime']);
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
