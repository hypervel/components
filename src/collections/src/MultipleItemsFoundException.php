<?php

declare(strict_types=1);

namespace Hypervel\Support;

use RuntimeException;
use Throwable;

class MultipleItemsFoundException extends RuntimeException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(
        public readonly int $count,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct("{$count} items were found.", $code, $previous);
    }

    /**
     * Get the number of items found.
     */
    public function getCount(): int
    {
        return $this->count;
    }
}
