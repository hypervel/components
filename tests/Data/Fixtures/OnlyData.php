<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class OnlyData extends Data
{
    protected static ?array $allowedOnly = null;

    /**
     * Create the name fixture.
     */
    public function __construct(
        public string $first_name,
        public string $last_name,
    ) {
    }

    /**
     * Get the only selections a request may ask for.
     */
    public static function allowedRequestOnly(): ?array
    {
        return self::$allowedOnly;
    }

    /**
     * Set the only selections a request may ask for.
     */
    public static function setAllowedOnly(?array $allowedOnly): void
    {
        self::$allowedOnly = $allowedOnly;
    }
}
