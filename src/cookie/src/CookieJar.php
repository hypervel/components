<?php

declare(strict_types=1);

namespace Hypervel\Cookie;

use Hypervel\Context\Context;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Cookie\QueueingFactory as JarContract;
use Hypervel\Support\Arr;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Traits\Macroable;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use UnitEnum;

use function Hypervel\Support\enum_value;

class CookieJar implements JarContract
{
    use InteractsWithTime;
    use Macroable;

    /**
     * Context key for the queued cookies.
     */
    protected const QUEUE_CONTEXT_KEY = '__cookie.queue';

    /**
     * The default path (if specified).
     */
    protected string $path = '/';

    /**
     * The default domain (if specified).
     */
    protected ?string $domain = null;

    /**
     * The default secure setting (defaults to null).
     */
    protected ?bool $secure = null;

    /**
     * The default SameSite option (defaults to lax).
     */
    protected ?string $sameSite = 'lax';

    /**
     * Determine if a cookie exists in the current request.
     */
    public function has(UnitEnum|string $key): bool
    {
        return ! is_null($this->get($key));
    }

    /**
     * Get a cookie value from the current request.
     */
    public function get(UnitEnum|string $key, ?string $default = null): ?string
    {
        $request = RequestContext::getOrNull();

        if ($request === null) {
            return null;
        }

        return $request->cookie(enum_value($key), $default);
    }

    /**
     * Create a new cookie instance.
     */
    public function make(UnitEnum|string $name, string $value, int $minutes = 0, ?string $path = null, ?string $domain = null, ?bool $secure = null, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null): Cookie
    {
        [$path, $domain, $secure, $sameSite] = $this->getPathAndDomain($path, $domain, $secure, $sameSite);

        $time = ($minutes === 0) ? 0 : $this->availableAt($minutes * 60);

        return new Cookie(enum_value($name), $value, $time, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
    }

    /**
     * Create a cookie that lasts "forever" (400 days).
     */
    public function forever(UnitEnum|string $name, string $value, ?string $path = null, ?string $domain = null, ?bool $secure = null, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null): Cookie
    {
        return $this->make($name, $value, 576000, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
    }

    /**
     * Expire the given cookie.
     */
    public function forget(UnitEnum|string $name, ?string $path = null, ?string $domain = null): Cookie
    {
        return $this->make($name, '', -2628000, $path, $domain);
    }

    /**
     * Determine if a cookie has been queued.
     */
    public function hasQueued(UnitEnum|string $key, ?string $path = null): bool
    {
        return ! is_null($this->queued(enum_value($key), null, $path));
    }

    /**
     * Get a queued cookie instance.
     */
    public function queued(UnitEnum|string $key, mixed $default = null, ?string $path = null): ?SymfonyCookie
    {
        $queued = Arr::get($this->getQueuedCookiesRaw(), enum_value($key), []);

        if ($path === null) {
            return $queued === [] ? $default : Arr::last($queued, null, $default);
        }

        return Arr::get($queued, $path, $default);
    }

    /**
     * Queue a cookie to send with the next response.
     */
    public function queue(mixed ...$parameters): void
    {
        if (isset($parameters[0]) && $parameters[0] instanceof SymfonyCookie) {
            $cookie = $parameters[0];
        } else {
            $cookie = $this->make(...array_values($parameters));
        }

        $cookies = $this->getQueuedCookiesRaw();

        if (! isset($cookies[$cookie->getName()])) {
            $cookies[$cookie->getName()] = [];
        }

        $cookies[$cookie->getName()][$cookie->getPath()] = $cookie;

        $this->setQueuedCookies($cookies);
    }

    /**
     * Queue a cookie to expire with the next response.
     */
    public function expire(UnitEnum|string $name, ?string $path = null, ?string $domain = null): void
    {
        $this->queue($this->forget($name, $path, $domain));
    }

    /**
     * Remove a cookie from the queue.
     */
    public function unqueue(UnitEnum|string $name, ?string $path = null): void
    {
        $name = enum_value($name);

        $cookies = $this->getQueuedCookiesRaw();

        if ($path === null) {
            unset($cookies[$name]);

            $this->setQueuedCookies($cookies);
            return;
        }

        unset($cookies[$name][$path]);

        if (empty($cookies[$name])) {
            unset($cookies[$name]);
        }

        $this->setQueuedCookies($cookies);
    }

    /**
     * Get the path and domain, or the default values.
     */
    protected function getPathAndDomain(?string $path, ?string $domain, ?bool $secure = null, ?string $sameSite = null): array
    {
        return [$path ?: $this->path, $domain ?: $this->domain, is_bool($secure) ? $secure : $this->secure, $sameSite ?: $this->sameSite];
    }

    /**
     * Set the default path and domain for the jar.
     */
    public function setDefaultPathAndDomain(string $path, ?string $domain, ?bool $secure = false, ?string $sameSite = null): static
    {
        [$this->path, $this->domain, $this->secure, $this->sameSite] = [$path, $domain, $secure, $sameSite];

        return $this;
    }

    /**
     * Get the cookies which have been queued for the next request.
     */
    public function getQueuedCookies(): array
    {
        return Arr::flatten($this->getQueuedCookiesRaw());
    }

    /**
     * Flush the cookies which have been queued for the next request.
     */
    public function flushQueuedCookies(): static
    {
        $this->setQueuedCookies([]);

        return $this;
    }

    /**
     * Get the raw queued cookies array (keyed by name and path).
     */
    protected function getQueuedCookiesRaw(): array
    {
        return Context::get(self::QUEUE_CONTEXT_KEY, []);
    }

    /**
     * Set the queued cookies in the coroutine context.
     */
    protected function setQueuedCookies(array $cookies): void
    {
        Context::set(self::QUEUE_CONTEXT_KEY, $cookies);
    }
}
