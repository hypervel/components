<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers\Concerns;

trait CompilesAuthorizations
{
    /**
     * Compile the can statements into valid PHP.
     */
    protected function compileCan(string $expression): string
    {
        return "<?php if (app(\\Hypervel\\Contracts\\Auth\\Access\\Gate::class)->check{$expression}): ?>";
    }

    /**
     * Compile the cannot statements into valid PHP.
     */
    protected function compileCannot(string $expression): string
    {
        return "<?php if (app(\\Hypervel\\Contracts\\Auth\\Access\\Gate::class)->denies{$expression}): ?>";
    }

    /**
     * Compile the canany statements into valid PHP.
     */
    protected function compileCanany(string $expression): string
    {
        return "<?php if (app(\\Hypervel\\Contracts\\Auth\\Access\\Gate::class)->any{$expression}): ?>";
    }

    /**
     * Compile the else-can statements into valid PHP.
     */
    protected function compileElsecan(string $expression): string
    {
        return "<?php elseif (app(\\Hypervel\\Contracts\\Auth\\Access\\Gate::class)->check{$expression}): ?>";
    }

    /**
     * Compile the else-cannot statements into valid PHP.
     */
    protected function compileElsecannot(string $expression): string
    {
        return "<?php elseif (app(\\Hypervel\\Contracts\\Auth\\Access\\Gate::class)->denies{$expression}): ?>";
    }

    /**
     * Compile the else-canany statements into valid PHP.
     */
    protected function compileElsecanany(string $expression): string
    {
        return "<?php elseif (app(\\Hypervel\\Contracts\\Auth\\Access\\Gate::class)->any{$expression}): ?>";
    }

    /**
     * Compile the end-can statements into valid PHP.
     */
    protected function compileEndcan(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the end-cannot statements into valid PHP.
     */
    protected function compileEndcannot(): string
    {
        return '<?php endif; ?>';
    }

    /**
     * Compile the end-canany statements into valid PHP.
     */
    protected function compileEndcanany(): string
    {
        return '<?php endif; ?>';
    }
}
