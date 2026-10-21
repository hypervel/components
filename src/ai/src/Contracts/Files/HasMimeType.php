<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Files;

interface HasMimeType
{
    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string;

    /**
     * Set the file's MIME type.
     */
    public function withMimeType(string $mimeType): static;
}
