<?php

declare(strict_types=1);

namespace Hypervel\Database\Query;

class IndexHint
{
    /**
     * Create a new index hint instance.
     */
    public function __construct(
        public string $type,
        public string $index,
    ) {
    }
}
