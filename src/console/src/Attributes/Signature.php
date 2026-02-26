<?php

declare(strict_types=1);

namespace Hypervel\Console\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Signature
{
    /**
     * Create a new attribute instance.
     *
     * @param null|string[] $aliases
     */
    public function __construct(
        public string $signature,
        public ?array $aliases = null,
    ) {
    }
}
