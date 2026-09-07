<?php

declare(strict_types=1);

namespace Hypervel\Image;

class ImageOutputOptions
{
    /**
     * The default output quality.
     */
    public const int DEFAULT_QUALITY = 70;

    /**
     * The output format.
     *
     * @var null|'avif'|'bmp'|'gif'|'heic'|'jpeg'|'jpg'|'png'|'webp'
     */
    public ?string $format = null;

    /**
     * The output quality (1-100).
     *
     * @var null|int<1, 100>
     */
    public ?int $quality = null;

    /**
     * Determine if any output options have been set.
     */
    public function hasChanges(): bool
    {
        return $this->format !== null || $this->quality !== null;
    }
}
