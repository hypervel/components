<?php

declare(strict_types=1);

namespace Hypervel\Image\Transformations;

use Hypervel\Contracts\Image\Transformation;

class Resize implements Transformation
{
    /**
     * @param null|positive-int $width
     * @param null|positive-int $height
     */
    public function __construct(
        public readonly ?int $width,
        public readonly ?int $height,
    ) {
    }
}
