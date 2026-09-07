<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;

class PaginatedCollectionIsAlwaysWrapped extends Exception
{
    /**
     * Create a paginated wrapping exception.
     */
    public static function create(): self
    {
        return new self('A paginated data collection is always wrapped');
    }
}
