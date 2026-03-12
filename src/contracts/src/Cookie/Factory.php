<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Cookie;

use Symfony\Component\HttpFoundation\Cookie;
use UnitEnum;

interface Factory
{
    /**
     * Create a new cookie instance.
     */
    public function make(UnitEnum|string $name, string $value, int $minutes = 0, ?string $path = null, ?string $domain = null, ?bool $secure = null, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null): Cookie;

    /**
     * Create a cookie that lasts "forever" (five years).
     */
    public function forever(UnitEnum|string $name, string $value, ?string $path = null, ?string $domain = null, ?bool $secure = null, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null): Cookie;

    /**
     * Expire the given cookie.
     */
    public function forget(UnitEnum|string $name, ?string $path = null, ?string $domain = null): Cookie;
}
