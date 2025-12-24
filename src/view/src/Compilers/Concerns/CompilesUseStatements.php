<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

trait CompilesUseStatements
{
    /**
     * Compile the use statements into valid PHP.
     */
    protected function compileUse(string $expression): string
    {
        $segments = explode(',', preg_replace('/[\\(\\)]/', '', $expression));

        $use = ltrim(trim($segments[0], " '\""), '\\');
        $as = isset($segments[1]) ? ' as ' . trim($segments[1], " '\"") : '';

        return "<?php use \\{$use}{$as}; ?>";
    }
}
