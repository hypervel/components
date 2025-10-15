<?php

namespace Hypervel\View\Compilers\Concerns;

trait CompilesClasses
{
    /**
     * Compile the conditional class statement into valid PHP.
     *
     * @param  string|null  $expression
     * @return string
     */
    protected function compileClass(?string $expression): string
    {
        $expression = is_null($expression) ? '([])' : $expression;

        return "class=\"<?php echo \Hypervel\Support\Arr::toCssClasses{$expression}; ?>\"";
    }
}
