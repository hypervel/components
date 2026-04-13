<?php

declare(strict_types=1);

namespace Hypervel\Testing;

/**
 * A fake Swoole response socket for use in tests.
 *
 * Records headers, cookies, and status code that would normally
 * be sent directly to the Swoole HTTP response object.
 */
class FakeSwooleSocket
{
    public array $headers = [];

    public array $cookies = [];

    public int $statusCode = 200;

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function cookie(
        string $name,
        string $value = '',
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false,
        string $samesite = '',
    ): void {
        $this->cookies[$name] = [
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ];
    }

    public function status(int $code): void
    {
        $this->statusCode = $code;
    }
}
