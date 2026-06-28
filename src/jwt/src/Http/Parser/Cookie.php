<?php

declare(strict_types=1);

namespace Hypervel\JWT\Http\Parser;

use Hypervel\Http\Request;
use Hypervel\JWT\Contracts\TokenExtractor;

class Cookie implements TokenExtractor
{
    /**
     * Create a new cookie parser.
     */
    public function __construct(
        protected string $key = 'token'
    ) {
    }

    /**
     * Parse a token from request cookies.
     */
    public function parseToken(Request $request): ?string
    {
        $token = $request->cookie($this->key);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
