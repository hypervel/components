<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Http\Parser;

use Hypervel\Http\Request;
use Hypervel\Jwt\Contracts\TokenExtractor;

class AuthHeaders implements TokenExtractor
{
    /**
     * Parse a bearer token from the request headers.
     */
    public function parseToken(Request $request): ?string
    {
        $header = $request->header('Authorization')
            ?: $request->server('HTTP_AUTHORIZATION')
            ?: $request->server('REDIRECT_HTTP_AUTHORIZATION');

        if (! is_string($header)) {
            return null;
        }

        foreach (explode(',', $header) as $segment) {
            $segment = trim($segment);

            if (strncasecmp($segment, 'Bearer ', 7) === 0) {
                return trim(substr($segment, 7)) ?: null;
            }
        }

        return null;
    }
}
