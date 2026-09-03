<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

final readonly class ValidationContext
{
    /**
     * Create validation context for one data node.
     */
    public function __construct(
        public mixed $payload,
        public mixed $fullPayload,
        public ValidationPath $path,
    ) {
    }
}
