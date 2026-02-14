<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers;

interface CompilerInterface
{
    /**
     * Get the path to the compiled version of a view.
     */
    public function getCompiledPath(string $path): string;

    /**
     * Determine if the given view is expired.
     */
    public function isExpired(string $path): bool;

    /**
     * Compile the view at the given path.
     */
    public function compile(string $path): void;
}
