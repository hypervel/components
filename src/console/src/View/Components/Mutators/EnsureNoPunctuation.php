<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components\Mutators;

use Hypervel\Support\Stringable;

class EnsureNoPunctuation
{
    /**
     * Ensure the given string does not end with punctuation.
     */
    public function __invoke(string $string): string
    {
        if ((new Stringable($string))->endsWith(['.', '?', '!', ':'])) {
            return substr_replace($string, '', -1);
        }

        return $string;
    }
}
