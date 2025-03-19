<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

trait CompilesAuthorization
{
    /**
     * Compile the can statements into valid PHP.
     */
    protected function compileCan(string $expression): string
    {
        return "<?php if (app(\\Hypervel\\Auth\\Contracts::class)->check{$expression}): ?>";
    }

    /**
     * Compile the cannot statements into valid PHP.
     */
    protected function compileCannot(string $expression): string
    {
        return "<?php if (app(\\Hypervel\\Auth\\Contracts::class)->denies{$expression}): ?>";
    }

    /**
     * Compile the canany statements into valid PHP.
     */
    protected function compileCanany(string $expression): string
    {
        return "<?php if (app(\\Hypervel\\Auth\\Contracts::class)->any{$expression}): ?>";
    }

    /**
     * Compile the else-can statements into valid PHP.
     */
    protected function compileElsecan(string $expression): string
    {
        return "<?php elseif (app(\\Hypervel\\Auth\\Contracts::class)->check{$expression}): ?>";
    }

    /**
     * Compile the else-cannot statements into valid PHP.
     */
    protected function compileElsecannot(string $expression): string
    {
        return "<?php elseif (app(\\Hypervel\\Auth\\Contracts::class)->denies{$expression}): ?>";
    }

    /**
     * Compile the else-canany statements into valid PHP.
     */
    protected function compileElsecanany(string $expression): string
    {
        return "<?php elseif (app(\\Hypervel\\Auth\\Contracts::class)->any{$expression}): ?>";
    }

    /**
     * Compile the end-can statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndcan()
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the end-cannot statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndcannot()
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the end-canany statements into valid PHP.
     *
     * @return string
     */
    protected function compileEndcanany()
    {
        return '<?php endif; ?>';
    }
}
