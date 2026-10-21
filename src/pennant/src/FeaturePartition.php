<?php

declare(strict_types=1);

namespace Hypervel\Pennant;

readonly class FeaturePartition
{
    /**
     * Create a resolved feature partition.
     */
    public function __construct(
        public string $column,
        public int|string $value,
    ) {
    }

    /**
     * Get the key that identifies the partition in memoized and cached values.
     *
     * The integer and string forms of a value select the same rows, so they share a key.
     */
    public function key(): string
    {
        $value = (string) $this->value;

        return strlen($this->column) . ':' . $this->column . strlen($value) . ':' . $value;
    }
}
