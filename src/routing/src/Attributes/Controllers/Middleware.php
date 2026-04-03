<?php

declare(strict_types=1);

namespace Hypervel\Routing\Attributes\Controllers;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware
{
    /**
     * Create a new middleware attribute instance.
     *
     * @param null|array<string> $only
     * @param null|array<string> $except
     */
    public function __construct(
        public readonly string $value,
        public readonly ?array $only = null,
        public readonly ?array $except = null,
    ) {
    }
}
