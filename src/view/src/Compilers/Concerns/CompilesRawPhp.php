<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

trait CompilesRawPhp
{
    /**
     * Compile the raw PHP statements into valid PHP.
     *
     * @param  string|null  $expression
     * @return string
     */
    protected function compilePhp(?string $expression): string
    {
        if ($expression) {
            return "<?php {$expression}; ?>";
        }

        return '@php';
    }

    /**
     * Compile the unset statements into valid PHP.
     *
     * @param  string  $expression
     * @return string
     */
    protected function compileUnset(string $expression): string
    {
        return "<?php unset{$expression}; ?>";
    }
}
