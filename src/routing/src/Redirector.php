<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use BackedEnum;
use DateInterval;
use DateTimeInterface;
use Hypervel\Http\RedirectResponse;
use Hypervel\Session\Store as SessionStore;
use Hypervel\Support\Traits\Macroable;

class Redirector
{
    use Macroable;

    /**
     * The URL generator instance.
     */
    protected UrlGenerator $generator;

    /**
     * The session store instance.
     */
    protected SessionStore $session;

    /**
     * Create a new Redirector instance.
     */
    public function __construct(UrlGenerator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Create a new redirect response to the previous location.
     */
    public function back(int $status = 302, array $headers = [], bool|string $fallback = false): RedirectResponse
    {
        return $this->createRedirect($this->generator->previous($fallback), $status, $headers);
    }

    /**
     * Create a new redirect response to the current URI.
     */
    public function refresh(int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->to($this->generator->getRequest()->path(), $status, $headers);
    }

    /**
     * Create a new redirect response, while putting the current URL in the session.
     */
    public function guest(string $path, int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse
    {
        $request = $this->generator->getRequest();

        $intended = $request->isMethod('GET') && $request->route() && ! $request->expectsJson()
            ? $this->generator->full()
            : $this->generator->previous();

        if ($intended) {
            $this->setIntendedUrl($intended);
        }

        return $this->to($path, $status, $headers, $secure);
    }

    /**
     * Create a new redirect response to the previously intended location.
     */
    public function intended(string $default = '/', int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse
    {
        $path = $this->session->pull('url.intended', $default);

        return $this->to($path, $status, $headers, $secure);
    }

    /**
     * Create a new redirect response to the given path.
     */
    public function to(string $path, int $status = 302, array $headers = [], ?bool $secure = null): RedirectResponse
    {
        return $this->createRedirect($this->generator->to($path, [], $secure), $status, $headers);
    }

    /**
     * Create a new redirect response to an external URL (no validation).
     */
    public function away(string $path, int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->createRedirect($path, $status, $headers);
    }

    /**
     * Create a new redirect response to the given HTTPS path.
     */
    public function secure(string $path, int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->to($path, $status, $headers, true);
    }

    /**
     * Create a new redirect response to a named route.
     */
    public function route(BackedEnum|string $route, array|string $parameters = [], int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->to($this->generator->route($route, $parameters), $status, $headers);
    }

    /**
     * Create a new redirect response to a signed named route.
     */
    public function signedRoute(BackedEnum|string $route, array|string $parameters = [], DateInterval|DateTimeInterface|int|null $expiration = null, int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->to($this->generator->signedRoute($route, $parameters, $expiration), $status, $headers);
    }

    /**
     * Create a new redirect response to a temporary signed named route.
     */
    public function temporarySignedRoute(BackedEnum|string $route, DateInterval|DateTimeInterface|int $expiration, array $parameters = [], int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->to($this->generator->temporarySignedRoute($route, $expiration, $parameters), $status, $headers);
    }

    /**
     * Create a new redirect response to a controller action.
     */
    public function action(array|string $action, array|string $parameters = [], int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->to($this->generator->action($action, $parameters), $status, $headers);
    }

    /**
     * Create a new redirect response.
     */
    protected function createRedirect(string $path, int $status, array $headers): RedirectResponse
    {
        return tap(new RedirectResponse($path, $status, $headers), function ($redirect) {
            if (isset($this->session)) {
                $redirect->setSession($this->session);
            }

            $redirect->setRequest($this->generator->getRequest());
        });
    }

    /**
     * Get the URL generator instance.
     */
    public function getUrlGenerator(): UrlGenerator
    {
        return $this->generator;
    }

    /**
     * Set the active session store.
     */
    public function setSession(SessionStore $session): void
    {
        $this->session = $session;
    }

    /**
     * Get the "intended" URL from the session.
     */
    public function getIntendedUrl(): ?string
    {
        return $this->session->get('url.intended');
    }

    /**
     * Set the "intended" URL in the session.
     *
     * @return $this
     */
    public function setIntendedUrl(string $url): static
    {
        $this->session->put('url.intended', $url);

        return $this;
    }
}
