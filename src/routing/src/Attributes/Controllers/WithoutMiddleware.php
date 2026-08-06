<?php

declare(strict_types=1);

namespace Hypervel\Routing\Attributes\Controllers;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class WithoutMiddleware
{
    /**
     * @param null|array<string> $only
     * @param null|array<string> $except
     */
    public function __construct(
        public string $middleware,
        public ?array $only = null,
        public ?array $except = null,
    ) {
    }
}
