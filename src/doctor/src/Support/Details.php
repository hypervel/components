<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Support;

final class Details
{
    /**
     * Format a list of strings as bullets.
     *
     * @param list<string> $items
     */
    public static function bullets(array $items): string
    {
        return collect($items)
            ->map(static fn (string $item): string => str($item)->start('- ')->toString())
            ->implode(PHP_EOL);
    }

    /**
     * Format keyed failure messages.
     *
     * @param array<string, string> $failures
     */
    public static function failures(array $failures): string
    {
        return collect($failures)
            ->map(static fn (string $message, string $name): string => sprintf('- %s: %s', $name, $message))
            ->implode(PHP_EOL);
    }

    /**
     * Get the most useful process output.
     */
    public static function processOutput(string $output, string $errorOutput, string $fallback): string
    {
        $output = trim($errorOutput !== '' ? $errorOutput : $output);

        return $output === '' ? $fallback : $output;
    }
}
