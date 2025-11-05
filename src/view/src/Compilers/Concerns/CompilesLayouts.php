<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

trait CompilesLayouts
{
    /**
     * The name of the last section that was started.
     */
    protected ?string $lastSection = null;

    /**
     * Compile the extends statements into valid PHP.
     */
    protected function compileExtends(string $expression): string
    {
        $expression = $this->stripParentheses($expression);

        $echo = "<?php echo \$__env->make({$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>";

        $this->footer[] = $echo;

        return '';
    }

    /**
     * Compile the extends-first statements into valid PHP.
     */
    protected function compileExtendsFirst(string $expression): string
    {
        $expression = $this->stripParentheses($expression);

        $echo = "<?php echo \$__env->first({$expression}, array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>";

        $this->footer[] = $echo;

        return '';
    }

    /**
     * Compile the section statements into valid PHP.
     */
    protected function compileSection(string $expression): string
    {
        $this->lastSection = trim($expression, "()'\" ");

        return "<?php \$__env->startSection{$expression}; ?>";
    }

    /**
     * Replace the @parent directive to a placeholder.
     */
    protected function compileParent(): string
    {
        $escapedLastSection = strtr($this->lastSection, ['\\' => '\\\\', "'" => "\\'"]);

        return "<?php echo \Hypervel\View\Factory::parentPlaceholder('{$escapedLastSection}'); ?>";
    }

    /**
     * Compile the yield statements into valid PHP.
     */
    protected function compileYield(string $expression): string
    {
        return "<?php echo \$__env->yieldContent{$expression}; ?>";
    }

    /**
     * Compile the show statements into valid PHP.
     */
    protected function compileShow(): string
    {
        return '<?php echo $__env->yieldSection(); ?>';
    }

    /**
     * Compile the append statements into valid PHP.
     */
    protected function compileAppend(): string
    {
        return '<?php $__env->appendSection(); ?>';
    }

    /**
     * Compile the overwrite statements into valid PHP.
     */
    protected function compileOverwrite(): string
    {
        return '<?php $__env->stopSection(true); ?>';
    }

    /**
     * Compile the stop statements into valid PHP.
     */
    protected function compileStop(): string
    {
        return '<?php $__env->stopSection(); ?>';
    }

    /**
     * Compile the end-section statements into valid PHP.
     */
    protected function compileEndsection(): string
    {
        return '<?php $__env->stopSection(); ?>';
    }
}
