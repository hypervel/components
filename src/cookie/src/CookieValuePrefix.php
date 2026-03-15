<?php

declare(strict_types=1);

namespace Hypervel\Cookie;

class CookieValuePrefix
{
    /**
     * Create a new cookie value prefix for the given cookie name.
     */
    public static function create(string $cookieName, string $key): string
    {
        return hash_hmac('sha1', $cookieName . 'v2', $key) . '|';
    }

    /**
     * Remove the cookie value prefix.
     */
    public static function remove(string $cookieValue): string
    {
        return substr($cookieValue, 41);
    }

    /**
     * Validate a cookie value contains a valid prefix.
     *
     * If it does, return the cookie value with the prefix removed. Otherwise, return null.
     */
    public static function validate(string $cookieName, string $cookieValue, array $keys): ?string
    {
        foreach ($keys as $key) {
            $hasValidPrefix = str_starts_with($cookieValue, static::create($cookieName, $key));

            if ($hasValidPrefix) {
                return static::remove($cookieValue);
            }
        }

        return null;
    }
}
