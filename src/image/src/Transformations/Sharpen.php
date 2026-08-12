<?php

declare(strict_types=1);

namespace Hypervel\Image\Transformations;

use Hypervel\Contracts\Image\Transformation;

class Sharpen implements Transformation
{
    public function __construct(public readonly int $amount)
    {
    }
}
