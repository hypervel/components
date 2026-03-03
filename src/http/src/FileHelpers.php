<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hypervel\Support\Str;

trait FileHelpers
{
    /**
     * The cache copy of the file's hash name.
     */
    protected ?string $hashName = null;

    /**
     * Get the fully-qualified path to the file.
     */
    public function path(): string
    {
        return $this->getRealPath();
    }

    /**
     * Get the file's extension.
     */
    public function extension(): string
    {
        return $this->guessExtension();
    }

    /**
     * Get a filename for the file.
     */
    public function hashName(?string $path = null): string
    {
        if ($path) {
            $path = rtrim($path, '/') . '/';
        }

        $hash = $this->hashName ?: $this->hashName = Str::random(40);

        if ($extension = $this->guessExtension()) {
            $extension = '.' . $extension;
        }

        return $path . $hash . $extension;
    }

    /**
     * Get the dimensions of the image (if applicable).
     */
    public function dimensions(): ?array
    {
        return @getimagesize($this->getRealPath()) ?: null;
    }
}
