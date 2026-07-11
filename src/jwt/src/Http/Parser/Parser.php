<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Http\Parser;

use Hypervel\Http\Request;
use Hypervel\Jwt\Contracts\TokenExtractor;

class Parser
{
    /**
     * Create a new token parser.
     *
     * @param array<int, TokenExtractor> $chain
     */
    public function __construct(
        protected array $chain
    ) {
    }

    /**
     * Parse a token from the request.
     */
    public function parseToken(Request $request): ?string
    {
        foreach ($this->chain as $parser) {
            $token = $parser->parseToken($request);

            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return null;
    }
}
