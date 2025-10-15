<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

trait CompilesStyles
{
    /**
     * Compile the conditional style statement into valid PHP.
     *
     * @param  string|null  $expression
     * @return string
     */
    protected function compileStyle(?string $expression): string
    {
        $expression = is_null($expression) ? '([])' : $expression;

        return "style=\"<?php echo \Hypervel\Support\Arr::toCssStyles{$expression} ?>\"";
    }
}
