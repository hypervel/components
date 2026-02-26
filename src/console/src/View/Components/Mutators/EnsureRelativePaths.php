<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components\Mutators;

class EnsureRelativePaths
{
    /**
     * Ensure the given string only contains relative paths.
     */
    public function __invoke(string $string): string
    {
        if (function_exists('app') && app()->has('path.base')) {
            $string = str_replace(base_path() . '/', '', $string);
        }

        return $string;
    }
}
