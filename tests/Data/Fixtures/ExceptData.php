<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class ExceptData extends Data
{
    protected static ?array $allowedExcept = null;

    /**
     * Create the name fixture.
     */
    public function __construct(
        public string $first_name,
        public string $last_name,
    ) {
    }

    /**
     * Get the except selections a request may ask for.
     */
    public static function allowedRequestExcept(): ?array
    {
        return self::$allowedExcept;
    }

    /**
     * Set the except selections a request may ask for.
     */
    public static function setAllowedExcept(?array $allowedExcept): void
    {
        self::$allowedExcept = $allowedExcept;
    }
}
