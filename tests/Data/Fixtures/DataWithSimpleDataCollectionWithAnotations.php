<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;
use Hypervel\Tests\Data\Fixtures\Collections\SimpleDataCollectionWithAnotations;

class DataWithSimpleDataCollectionWithAnotations extends Data
{
    /**
     * Create a data object holding an annotated collection.
     */
    public function __construct(
        public SimpleDataCollectionWithAnotations $collection
    ) {
    }
}
