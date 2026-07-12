<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Contracts;

use Hypervel\Http\Request;

interface TokenExtractor
{
    /**
     * Parse a token from the request.
     */
    public function parseToken(Request $request): ?string;
}
