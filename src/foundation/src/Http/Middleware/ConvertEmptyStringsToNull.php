<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

class ConvertEmptyStringsToNull extends TransformsRequest
{
    protected function processString(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
