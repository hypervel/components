<?php

declare(strict_types=1);

namespace Hypervel\Cookie;

use DateTimeInterface;
use Hyperf\HttpMessage\Cookie\Cookie as HyperfCookie;
use InvalidArgumentException;

class Cookie extends HyperfCookie
{
    /**
     * Creates a cookie copy with a new value.
     */
    public function withValue(?string $value): static
    {
        $cookie = clone $this;
        $cookie->value = $value;

        return $cookie;
    }

    /**
     * Creates a cookie copy with a new domain that the cookie is available to.
     */
    public function withDomain(?string $domain): static
    {
        $cookie = clone $this;
        $cookie->domain = $domain;

        return $cookie;
    }

    /**
     * Creates a cookie copy with a new time the cookie expires.
     *
     * @param DateTimeInterface|int|string $expire
     */
    public function withExpires($expire = 0): static
    {
        $cookie = clone $this;
        $cookie->expire = static::expiresTimestamp($expire);

        return $cookie;
    }

    /**
     * Converts expires formats to a unix timestamp.
     *
     * @param DateTimeInterface|int|string $expire
     */
    protected static function expiresTimestamp($expire = 0): int
    {
        // convert expiration time to a Unix timestamp
        if ($expire instanceof DateTimeInterface) {
            $expire = $expire->format('U');
        } elseif (! is_numeric($expire)) {
            $expire = strtotime($expire);

            if ($expire === false) {
                throw new InvalidArgumentException('The cookie expiration time is not valid.');
            }
        }

        return 0 < $expire ? (int) $expire : 0;
    }

    /**
     * Creates a cookie copy with a new path on the server in which the cookie will be available on.
     */
    public function withPath(string $path): static
    {
        $cookie = clone $this;
        $cookie->path = $path === '' ? '/' : $path;

        return $cookie;
    }

    /**
     * Creates a cookie copy that only be transmitted over a secure HTTPS connection from the client.
     */
    public function withSecure(bool $secure = true): static
    {
        $cookie = clone $this;
        $cookie->secure = $secure;

        return $cookie;
    }

    /**
     * Creates a cookie copy that be accessible only through the HTTP protocol.
     */
    public function withHttpOnly(bool $httpOnly = true): static
    {
        $cookie = clone $this;
        $cookie->httpOnly = $httpOnly;

        return $cookie;
    }
}
