<?php

declare(strict_types=1);

namespace Hypervel\Passport\Attributes;

use Attribute;
use Hypervel\Passport\Http\Middleware\CheckToken;
use Hypervel\Passport\Http\Middleware\CheckTokenForAnyScope;
use Hypervel\Routing\Attributes\Controllers\Middleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class AuthorizeToken extends Middleware
{
    /**
     * Create a token scope authorization attribute.
     *
     * @param array<string>|string $scopes
     * @param null|array<string> $only
     * @param null|array<string> $except
     */
    public function __construct(
        array|string $scopes,
        bool $anyScope = false,
        ?array $only = null,
        ?array $except = null,
    ) {
        parent::__construct(
            $anyScope ? CheckTokenForAnyScope::using($scopes) : CheckToken::using($scopes),
            $only,
            $except,
        );
    }
}
