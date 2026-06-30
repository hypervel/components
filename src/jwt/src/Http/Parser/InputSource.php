<?php

declare(strict_types=1);

namespace Hypervel\JWT\Http\Parser;

use Hypervel\Http\Request;
use Hypervel\JWT\Contracts\TokenExtractor;

class InputSource implements TokenExtractor
{
    /**
     * Create a new input source parser.
     */
    public function __construct(
        protected string $key = 'token'
    ) {
    }

    /**
     * Parse a token from request input.
     */
    public function parseToken(Request $request): ?string
    {
        $token = $request->input($this->key);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
