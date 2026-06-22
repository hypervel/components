<?php

declare(strict_types=1);

namespace Hypervel\Routing\Attributes\Controllers;

use Attribute;
use Hypervel\Auth\Middleware\Authorize as AuthorizeMiddleware;
use Hypervel\Support\Arr;
use UnitEnum;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Authorize extends Middleware
{
    /**
     * Create a new authorize middleware attribute instance.
     *
     * @param null|array<string>|string $models
     * @param null|array<string> $only
     * @param null|array<string> $except
     */
    public function __construct(
        UnitEnum|string $ability,
        array|string|null $models = null,
        ?array $only = null,
        ?array $except = null,
    ) {
        $middleware = AuthorizeMiddleware::using($ability, ...Arr::wrap($models));

        parent::__construct($middleware, $only, $except);
    }
}
