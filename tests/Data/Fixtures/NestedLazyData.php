<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;
use Hypervel\Data\Lazy;

class NestedLazyData extends Data
{
    /**
     * Create a fixture with a lazy nested data object.
     */
    public function __construct(
        public SimpleData|Lazy $simple
    ) {
    }

    /**
     * Create the fixture with a lazily created nested object.
     */
    public static function fromString(string $string): static
    {
        return new self(Lazy::create(fn (): SimpleData => SimpleData::from($string)));
    }
}
