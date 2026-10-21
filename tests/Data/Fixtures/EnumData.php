<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;

class EnumData extends Data
{
    /**
     * Create a fixture with a backed enum property.
     */
    public function __construct(
        public DummyBackedEnum $enum
    ) {
    }
}
