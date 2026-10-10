<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\AbstractData;

class AbstractDataB extends AbstractData
{
    /**
     * Create the second concrete abstract-data fixture.
     */
    public function __construct(
        public string $b,
    ) {
    }
}
