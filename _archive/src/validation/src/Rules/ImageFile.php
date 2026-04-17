<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

class ImageFile extends File
{
    /**
     * Create a new image file rule instance.
     */
    public function __construct(bool $allowSvg = false)
    {
        if ($allowSvg) {
            $this->rules('image:allow_svg');
        } else {
            $this->rules('image');
        }
    }

    /**
     * The dimension constraints for the uploaded file.
     */
    public function dimensions(Dimensions $dimensions): static
    {
        $this->rules($dimensions);

        return $this;
    }
}
