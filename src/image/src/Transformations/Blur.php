<?php

declare(strict_types=1);

namespace Hypervel\Image\Transformations;

use Hypervel\Contracts\Image\Transformation;

class Blur implements Transformation
{
    /**
     * Create a new blur transformation.
     *
     * @param int<0, 100> $amount
     */
    public function __construct(public readonly int $amount)
    {
    }
}
