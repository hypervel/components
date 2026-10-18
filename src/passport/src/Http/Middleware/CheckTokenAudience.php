<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Middleware;

use Hypervel\Passport\AccessToken;
use Hypervel\Passport\Contracts\ScopeAuthorizable;
use Hypervel\Passport\Exceptions\AuthenticationException;

class CheckTokenAudience extends ValidateToken
{
    /**
     * Specify the accepted resource identifiers for the middleware.
     *
     * @param string|string[] $param
     */
    public static function using(array|string $param, string ...$params): string
    {
        // Escape commas, which the pipeline splits on, and percent signs so decoding restores the exact identifier.
        $resources = array_map(
            static fn (string $resource): string => strtr($resource, ['%' => '%25', ',' => '%2C']),
            is_array($param) ? $param : [$param, ...$params],
        );

        return static::class . ':' . implode(',', $resources);
    }

    /**
     * Require an access token intended for at least one configured resource.
     */
    protected function validate(ScopeAuthorizable $token, string ...$params): void
    {
        if (! $token instanceof AccessToken || array_intersect(array_map('rawurldecode', $params), $token->oauth_audiences) === []) {
            throw new AuthenticationException;
        }
    }
}
