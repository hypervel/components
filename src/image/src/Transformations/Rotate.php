<?php

declare(strict_types=1);

namespace Hypervel\Image\Transformations;

use Hypervel\Contracts\Image\Transformation;

class Rotate implements Transformation
{
    public function __construct(
        public readonly float $angle,
        public readonly ?string $background = null,
    ) {
    }
}
