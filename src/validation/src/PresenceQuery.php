<?php

declare(strict_types=1);

namespace Hypervel\Validation;

final readonly class PresenceQuery
{
    /**
     * Create the resolved query for one presence check.
     */
    public function __construct(
        public ?string $connection,
        public string $collection,
        public string $column,
        public DatabaseConditions $conditions,
        public int|string|null $excludeId = null,
        public ?string $idColumn = null,
    ) {
    }
}
