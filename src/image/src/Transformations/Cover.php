<?php

declare(strict_types=1);

namespace Hypervel\Image\Transformations;

use Hypervel\Contracts\Image\Transformation;

class Cover implements Transformation
{
    /**
     * Create a new cover transformation.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
