<?php

declare(strict_types=1);

namespace Hypervel\JWT\Http\Parser;

use Hypervel\Http\Request;
use Hypervel\JWT\Contracts\TokenExtractor;

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

        $position = strripos($header, 'Bearer');

        if ($position === false) {
            return null;
        }

        $token = substr($header, $position + strlen('Bearer'));

        return trim(str_contains($token, ',') ? strstr($token, ',', true) : $token) ?: null;
    }
}
