<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

class TrimStrings extends TransformsRequest
{
    protected function processString(string $value): ?string
    {
        return empty($value) ? $value : trim($value);
    }
}
