<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;

class MaxTransformationDepthReached extends Exception
{
    /**
     * Create an exception for the configured depth.
     */
    public static function create(int $depth): self
    {
        return new self("Max transformation depth of {$depth} reached.");
    }
}
