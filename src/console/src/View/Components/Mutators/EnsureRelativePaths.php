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
            // Shorten standalone paths without removing part of another path or URL.
            $string = (string) preg_replace('#(?<![^\s\[({<>\'"])' . preg_quote(base_path() . '/', '#') . '#', '', $string);
        }

        return $string;
    }
}
