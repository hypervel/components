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
     *
     * @return static
     */
    public function withValue(?string $value): self
    {
        $cookie = clone $this;
        $cookie->value = $value;

        return $cookie;
    }

    /**
     * Creates a cookie copy with a new domain that the cookie is available to.
     *
     * @return static
     */
    public function withDomain(?string $domain): self
    {
        $cookie = clone $this;
        $cookie->domain = $domain;

        return $cookie;
    }

    /**
     * Creates a cookie copy with a new time the cookie expires.
     *
     * @param DateTimeInterface|int|string $expire
     *
     * @return static
     */
    public function withExpires($expire = 0): self
    {
        $cookie = clone $this;
        $cookie->expire = self::expiresTimestamp($expire);

        return $cookie;
    }

    /**
     * Converts expires formats to a unix timestamp.
     *
     * @param DateTimeInterface|int|string $expire
     */
    private static function expiresTimestamp($expire = 0): int
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
     *
     * @return static
     */
    public function withPath(string $path): self
    {
        $cookie = clone $this;
        $cookie->path = $path === '' ? '/' : $path;

        return $cookie;
    }

    /**
     * Creates a cookie copy that only be transmitted over a secure HTTPS connection from the client.
     *
     * @return static
     */
    public function withSecure(bool $secure = true): self
    {
        $cookie = clone $this;
        $cookie->secure = $secure;

        return $cookie;
    }

    /**
     * Creates a cookie copy that be accessible only through the HTTP protocol.
     *
     * @return static
     */
    public function withHttpOnly(bool $httpOnly = true): self
    {
        $cookie = clone $this;
        $cookie->httpOnly = $httpOnly;

        return $cookie;
    }
}
