<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Stringable;
use UnitEnum;

use function Hypervel\Support\enum_value;

final readonly class Cooldown
{
    /**
     * Create a stable cooldown key.
     */
    private function __construct(public string $key)
    {
    }

    /**
     * Create a cooldown for the given resource.
     */
    public static function for(Stringable|UnitEnum|string|int|null $key): self
    {
        if ($key instanceof UnitEnum) {
            $key = enum_value($key);
        }

        return new self($key === null ? '' : (string) $key);
    }
}
