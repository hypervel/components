<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Cookie;

use Hyperf\HttpMessage\Cookie\Cookie as HyperfCookie;

interface Cookie
{
    public function has(string $key): bool;

    public function get(string $key, ?string $default = null): ?string;

    public function make(string $name, string $value, int $minutes = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null): HyperfCookie;

    public function queue(...$parameters): void;

    public function expire(string $name, string $path = '', string $domain = ''): void;

    public function unqueue(string $name, string $path = ''): void;

    public function getQueuedCookies(): array;

    public function forever(string $name, string $value, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null): HyperfCookie;

    public function forget(string $name, string $path = '', string $domain = ''): HyperfCookie;
}
