<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Type;

use Hypervel\Translation\Translator;

class StringTranslator extends Translator
{
    /**
     * Get the translation for the given key, which is always a single line.
     */
    public function get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): string
    {
        return $key;
    }
}
