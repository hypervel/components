<?php

declare(strict_types=1);

namespace Hypervel\Image\Transformations;

use Hypervel\Contracts\Image\Transformation;

class Rotate implements Transformation
{
    /**
     * Create a new rotate transformation.
     */
    public function __construct(
        public readonly float $angle,
        public readonly ?string $background = null,
    ) {
    }
}
