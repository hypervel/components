<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Override;
use Symfony\Component\HttpFoundation\ResponseHeaderBag as SymfonyResponseHeaderBag;

/**
 * Response headers optimized for Swoole emission.
 */
class ResponseHeaderBag extends SymfonyResponseHeaderBag
{
    /**
     * Return response headers without materializing unrelated values.
     */
    #[Override]
    public function all(?string $key = null): array
    {
        if ($key === null) {
            return parent::all();
        }

        $key = strtr($key, self::UPPER, self::LOWER);

        return $key === 'set-cookie'
            ? array_map('strval', $this->getCookies())
            : $this->headers[$key] ?? [];
    }

    /**
     * Determine whether a response header is present.
     */
    #[Override]
    public function has(string $key): bool
    {
        $key = strtr($key, self::UPPER, self::LOWER);

        return $key === 'set-cookie'
            ? $this->cookies !== []
            : array_key_exists($key, $this->headers);
    }

    /**
     * Return headers with their original capitalization, excluding cookies.
     */
    #[Override]
    public function allPreserveCaseWithoutCookies(): array
    {
        $headers = [];

        // Symfony's implementation first materializes Set-Cookie strings and
        // then removes them. Swoole sends cookies separately, so iterate the
        // already cookie-free header storage directly.
        foreach ($this->headers as $name => $values) {
            $headers[$this->headerNames[$name] ?? $name] = $values;
        }

        return $headers;
    }
}
