<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

trait CompilesIncludes
{
    /**
     * Compile the each statements into valid PHP.
     */
    protected function compileEach(string $expression): string
    {
        return "<?php echo \$__env->renderEach{$expression}; ?>";
    }

    /**
     * Compile the include statements into valid PHP.
     */
    protected function compileInclude(string $expression): string
    {
        $expression = $this->stripParentheses($expression);

        return "<?php echo \$__env->make({$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>";
    }

    /**
     * Compile the include-if statements into valid PHP.
     */
    protected function compileIncludeIf(string $expression): string
    {
        $expression = $this->stripParentheses($expression);

        return "<?php if (\$__env->exists({$expression})) echo \$__env->make({$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>";
    }

    /**
     * Compile the include-when statements into valid PHP.
     */
    protected function compileIncludeWhen(string $expression): string
    {
        $expression = $this->stripParentheses($expression);

        return "<?php echo \$__env->renderWhen({$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])); ?>";
    }

    /**
     * Compile the include-unless statements into valid PHP.
     */
    protected function compileIncludeUnless(string $expression): string
    {
        $expression = $this->stripParentheses($expression);

        return "<?php echo \$__env->renderUnless({$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])); ?>";
    }

    /**
     * Compile the include-first statements into valid PHP.
     */
    protected function compileIncludeFirst(string $expression): string
    {
        $expression = $this->stripParentheses($expression);

        return "<?php echo \$__env->first({$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>";
    }

    /**
     * Compile the include-isolated statements into valid PHP.
     */
    protected function compileIncludeIsolated(string $expression): string
    {
        $expression = $this->stripParentheses($expression);

        return "<?php echo \$__env->make({$expression})->render(); ?>";
    }
}
