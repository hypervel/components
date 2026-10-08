<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;
use Hypervel\Data\Lazy;

class LazyData extends Data
{
    protected static ?array $allowedIncludes = null;

    /**
     * Create the data object with a name that may be lazy.
     */
    public function __construct(
        public string|Lazy $name
    ) {
    }

    /**
     * Create the data object with a lazy name.
     */
    public static function fromString(string $name): static
    {
        return new static(Lazy::create(fn (): string => $name));
    }

    /**
     * Get the includes a request may ask for.
     */
    public static function allowedRequestIncludes(): ?array
    {
        return self::$allowedIncludes;
    }

    /**
     * Set the includes a request may ask for.
     */
    public static function setAllowedIncludes(?array $allowedIncludes): void
    {
        self::$allowedIncludes = $allowedIncludes;
    }
}
