<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

final readonly class DataMethodMatch
{
    /**
     * Create a new data method match.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function __construct(
        public array $arguments,
        public bool $requiresContainerCall,
    ) {
    }
}
