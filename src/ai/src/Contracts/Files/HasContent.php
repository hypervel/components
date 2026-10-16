<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Files;

use Stringable;

interface HasContent extends Stringable
{
    /**
     * Get the file's raw content.
     */
    public function content(): string;
}
