<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files\Concerns;

use InvalidArgumentException;

trait HasBase64Content
{
    /**
     * Get the raw representation of the file.
     */
    public function content(): string
    {
        $content = base64_decode($this->base64, true);

        if ($content === false) {
            throw new InvalidArgumentException('Invalid base64 file content.');
        }

        return $content;
    }
}
