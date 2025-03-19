<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers\Traits;

use Hyperf\Collection\Collection;
use Hyperf\Stringable\Str;

trait FetchesStackTrace
{
    /**
     * Find the first frame in the stack trace outside of Telescope/Hypervel.
     */
    protected function getCallerFromStackTrace(array $forgetLines = []): ?array
    {
        $trace = Collection::make(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
            ->forget($forgetLines);

        return $trace->first(function ($frame) {
            if (! isset($frame['file'])) {
                return false;
            }

            return ! Str::contains($frame['file'], $this->ignoredPaths());
        });
    }

    /**
     * Get the file paths that should not be used by backtraces.
     */
    protected function ignoredPaths(): array
    {
        $ignoredPaths = $this->shouldIgnoredVendorPath()
            ? [
                base_path('vendor' . DIRECTORY_SEPARATOR . 'hyperf'),
                base_path('vendor' . DIRECTORY_SEPARATOR . 'hypervel'),
            ]
            : [];

        return array_merge(
            $ignoredPaths,
            $this->options['ignore_paths'] ?? []
        );
    }

    /**
     * Indicates if to ignore ignore Telescope / Hypervel packages.
     */
    protected function shouldIgnoredVendorPath(): bool
    {
        return $this->options['ignore_packages'] ?? true;
    }
}
