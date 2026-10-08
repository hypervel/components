<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;
use Hypervel\Data\Lazy;

class DefaultLazyData extends Data
{
    protected static ?array $allowedExcludes = null;

    /**
     * Create the data object with a name that may be lazy.
     */
    public function __construct(
        public string|Lazy $name
    ) {
    }

    /**
     * Create the data object with a lazy name included by default.
     */
    public static function fromString(string $name): static
    {
        return new static(
            Lazy::create(fn (): string => $name)->defaultIncluded()
        );
    }

    /**
     * Get the excludes a request may ask for.
     */
    public static function allowedRequestExcludes(): ?array
    {
        return self::$allowedExcludes;
    }

    /**
     * Set the excludes a request may ask for.
     */
    public static function setAllowedExcludes(?array $allowedExcludes): void
    {
        self::$allowedExcludes = $allowedExcludes;
    }
}
