<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components\Mutators;

use Hypervel\Support\Stringable;

class EnsurePunctuation
{
    /**
     * Ensure the given string ends with punctuation.
     */
    public function __invoke(string $string): string
    {
        if (! (new Stringable($string))->endsWith(['.', '?', '!', ':'])) {
            return "{$string}.";
        }

        return $string;
    }
}
