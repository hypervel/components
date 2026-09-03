<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;

class CannotCreateDataCollectable extends Exception
{
    /**
     * Create a data collectable construction exception.
     */
    public static function create(
        string $from,
        string $into,
    ): self {
        return new self("Cannot create data collectable of type `{$into}` from `{$from}`");
    }

    /**
     * Create an exception for missing paginator reconstruction metadata.
     */
    public static function missingPaginatorSource(string $into): self
    {
        return new self(
            "Cannot create data collectable of type `{$into}` without a retained paginator source. "
            . 'Supply a Hypervel paginator so its pagination metadata can be preserved.'
        );
    }
}
