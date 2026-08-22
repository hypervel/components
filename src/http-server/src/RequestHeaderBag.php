<?php

declare(strict_types=1);

namespace Hypervel\HttpServer;

use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Store Swoole's already-separated headers directly, avoiding HeaderBag::set()
 * normalization for every header while still parsing Cache-Control once.
 *
 * @internal
 */
final class RequestHeaderBag extends HeaderBag
{
    public function __construct(array $headers = [])
    {
        foreach ($headers as $key => $values) {
            $key = strtr((string) $key, self::UPPER, self::LOWER);
            $this->headers[$key] = is_array($values)
                ? array_values($values)
                : [$values];
        }

        if (isset($this->headers['cache-control'])) {
            $this->cacheControl = $this->parseCacheControl(
                implode(', ', $this->headers['cache-control'])
            );
        }
    }
}
