<?php

declare(strict_types=1);

namespace Hypervel\View\Compilers;

interface CompilerInterface
{
    /**
     * Get the path to the compiled version of a view.
     */
    public function getCompiledPath($path);

    /**
     * Determine if the given view is expired.
     */
    public function isExpired($path);

    /**
     * Compile the view at the given path.
     */
    public function compile($path);
}
