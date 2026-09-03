<?php

declare(strict_types=1);

namespace Hypervel\Image\Transformations;

use Hypervel\Contracts\Image\Transformation;

class Resize implements Transformation
{
    /**
     * Create a new resize transformation.
     *
     * @param null|positive-int $width
     * @param null|positive-int $height
     */
    public function __construct(
        public readonly ?int $width,
        public readonly ?int $height,
    ) {
    }
}
