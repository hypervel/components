<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Exceptions\CannotFindDataClass;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DataCollectionOf
{
    /**
     * Create a new data collection type attribute.
     *
     * @param class-string<BaseData> $class
     */
    public function __construct(
        public readonly string $class,
    ) {
        if (! is_a($this->class, BaseData::class, true)) {
            throw CannotFindDataClass::forClass($this->class);
        }
    }
}
