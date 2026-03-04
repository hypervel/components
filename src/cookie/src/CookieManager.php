<?php

declare(strict_types=1);

namespace Hypervel\Cookie;

use Hypervel\Context\Context;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Cookie\Cookie as CookieContract;
use Hypervel\Support\InteractsWithTime;
use UnitEnum;

use function Hypervel\Support\enum_value;

class CookieManager implements CookieContract
{
    use InteractsWithTime;

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
    public function make(UnitEnum|string $name, string $value, int $minutes = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null): Cookie
    {
        $time = ($minutes === 0) ? 0 : $this->availableAt($minutes * 60);

        return new Cookie(enum_value($name), $value, $time, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
    }

    /**
     * Queue a cookie to send with the next response.
     */
    public function queue(...$parameters): void
    {
        if (isset($parameters[0]) && $parameters[0] instanceof Cookie) {
            $cookie = $parameters[0];
        } else {
            $cookie = $this->make(...array_values($parameters));
        }

        $this->appendToQueue($cookie);
    }

    /**
     * Queue a cookie to expire with the next response.
     */
    public function expire(UnitEnum|string $name, string $path = '', string $domain = ''): void
    {
        $this->queue($this->forget($name, $path, $domain));
    }

    /**
     * Remove a cookie from the queue.
     */
    public function unqueue(UnitEnum|string $name, string $path = ''): void
    {
        $name = enum_value($name);

        $cookies = $this->getQueuedCookies();
        if ($path === '') {
            unset($cookies[$name]);

            $this->setQueueCookies($cookies);
            return;
        }

        unset($cookies[$name][$path]);

        if (empty($cookies[$name])) {
            unset($cookies[$name]);
        }

        $this->setQueueCookies($cookies);
    }

    /**
     * Append a cookie to the queue.
     */
    protected function appendToQueue(Cookie $cookie): void
    {
        $cookies = $this->getQueuedCookies();
        $cookies[$cookie->getName()][$cookie->getPath()] = $cookie;

        $this->setQueueCookies($cookies);
    }

    /**
     * Get all of the queued cookies.
     */
    public function getQueuedCookies(): array
    {
        return Context::get('__cookie.queue', []);
    }

    /**
     * Set the queued cookies.
     */
    protected function setQueueCookies(array $cookies): array
    {
        return Context::set('__cookie.queue', $cookies);
    }

    /**
     * Create a cookie that lasts "forever" (five years).
     */
    public function forever(UnitEnum|string $name, string $value, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null): Cookie
    {
        return $this->make($name, $value, 2628000, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
    }

    /**
     * Expire the given cookie.
     */
    public function forget(UnitEnum|string $name, string $path = '', string $domain = ''): Cookie
    {
        return $this->make($name, '', -2628000, $path, $domain);
    }
}
