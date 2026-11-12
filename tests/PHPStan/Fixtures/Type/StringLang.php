<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Type;

use Hypervel\Support\Facades\Lang;

class StringLang extends Lang
{
    /**
     * Get the translation for the given key, which is always a single line.
     */
    public static function get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): string
    {
        return $key;
    }
}
