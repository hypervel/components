<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Data;

class SimpleDataWithMappedProperty extends Data
{
    /**
     * Create a data object whose string is read and written as its description.
     */
    public function __construct(
        #[MapName('description', 'description')]
        public string $string
    ) {
    }
}
