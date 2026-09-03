<?php

declare(strict_types=1);

namespace Hypervel\Image\Transformations;

use Hypervel\Contracts\Image\Transformation;

class Sharpen implements Transformation
{
    /**
     * Create a new sharpen transformation.
     *
     * @param int<0, 100> $amount
     */
    public function __construct(public readonly int $amount)
    {
    }
}
