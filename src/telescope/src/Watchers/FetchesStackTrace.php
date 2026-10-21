<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

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

            // A generated AOP proxy runs its source file's code, so the source decides whether the frame is ignored.
            $file = GenerateProxies::proxySources()[$frame['file']] ?? $frame['file'];

            return ! Str::contains($file, $this->ignoredPaths());
        });
    }

    /**
     * Get the file paths that should not be used by backtraces.
     */
    protected function ignoredPaths(): array
    {
        return array_merge(
            [base_path('vendor' . DIRECTORY_SEPARATOR . $this->ignoredVendorPath())],
            $this->options['ignore_paths'] ?? []
        );
    }

    /**
     * Choose the frame outside of either Telescope / Hypervel or all packages.
     */
    protected function ignoredVendorPath(): ?string
    {
        return ($this->options['ignore_packages'] ?? true) ? null : 'hypervel';
    }
}
