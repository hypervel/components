<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components\Mutators;

class EnsureDynamicContentIsHighlighted
{
    /**
     * Highlight dynamic content within the given string.
     */
    public function __invoke(string $string): string
    {
        return preg_replace('/\[([^\]]+)\]/', '<options=bold>[$1]</>', $string);
    }
}
