<?php

declare(strict_types=1);

namespace Hypervel\Support\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class BootOrTestsOnly
{
    /**
     * Create a new attribute instance.
     *
     * @param null|string $whenNotDefault the parameter whose values other than its declared default make the call restricted
     * @param null|string $whenProvided the parameter whose presence in the call makes it restricted
     * @param bool $whenShared whether the restriction applies only to a worker-shared receiver
     */
    public function __construct(
        public ?string $whenNotDefault = null,
        public ?string $whenProvided = null,
        public bool $whenShared = false,
    ) {
    }
}
