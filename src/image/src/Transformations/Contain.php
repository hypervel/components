<?php

declare(strict_types=1);

namespace Hypervel\Image\Transformations;

use Hypervel\Contracts\Image\Transformation;

class Contain implements Transformation
{
    /**
     * Create a new contain transformation.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly ?string $background = null,
    ) {
    }
}
