<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Cookie;

use UnitEnum;

interface QueueingFactory extends Factory
{
    /**
     * Determine if a cookie exists in the current request.
     */
    public function has(UnitEnum|string $key): bool;

    /**
     * Get a cookie value from the current request.
     */
    public function get(UnitEnum|string $key, ?string $default = null): ?string;

    /**
     * Queue a cookie to send with the next response.
     */
    public function queue(mixed ...$parameters): void;

    /**
     * Queue a cookie to expire with the next response.
     */
    public function expire(UnitEnum|string $name, ?string $path = null, ?string $domain = null): void;

    /**
     * Remove a cookie from the queue.
     */
    public function unqueue(UnitEnum|string $name, ?string $path = null): void;

    /**
     * Get the cookies which have been queued for the next request.
     */
    public function getQueuedCookies(): array;
}
