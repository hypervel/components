<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;

class SimpleChildDataWithMappedOutputName extends Data
{
    /**
     * Create a child fixture with a mapped output name.
     */
    public function __construct(
        public int $id,
        #[MapOutputName('child_amount')]
        public float $amount
    ) {
    }

    /**
     * Get the except selections a request may ask for.
     */
    public static function allowedRequestExcept(): ?array
    {
        return ['amount'];
    }
}
