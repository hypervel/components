<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

readonly class Issue
{
    /**
     * Describe uncertainty in the owning schema or table.
     */
    public function __construct(
        public string $message,
        public ?int $line = null,
    ) {
    }
}
