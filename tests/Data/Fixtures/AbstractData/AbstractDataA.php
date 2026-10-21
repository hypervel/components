<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\AbstractData;

class AbstractDataA extends AbstractData
{
    /**
     * Create the first concrete abstract-data fixture.
     */
    public function __construct(
        public string $a,
    ) {
    }
}
