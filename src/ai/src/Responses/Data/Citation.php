<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

abstract class Citation
{
    /**
     * Create a citation.
     */
    public function __construct(
        public ?string $title = null,
    ) {
    }
}
