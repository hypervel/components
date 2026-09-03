<?php

declare(strict_types=1);

namespace Hypervel\Image\Transformations;

use Hypervel\Contracts\Image\Transformation;

class Crop implements Transformation
{
    /**
     * Create a new crop transformation.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $x = 0,
        public readonly int $y = 0,
    ) {
    }
}
