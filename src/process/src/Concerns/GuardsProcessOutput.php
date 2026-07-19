<?php

declare(strict_types=1);

namespace Hypervel\Process\Concerns;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

trait GuardsProcessOutput
{
    /**
     * Stop the process if its output callback fails.
     */
    protected function guardProcessOutput(Process $process, ?callable $output): ?Closure
    {
        if ($output === null) {
            return null;
        }

        return function (string $type, string $buffer) use ($process, $output) {
            // This per-wrapper state survives stop() re-entry without leaking across processes.
            static $failed = false;

            // Stopping drains output and may re-enter this callback before cleanup completes.
            if ($failed) {
                return false;
            }

            try {
                return $output($type, $buffer);
            } catch (Throwable $exception) {
                $failed = true;

                try {
                    // SWOOLE_HOOK_PROC can reach an invalid native resource during coroutine teardown.
                    $process->stop(0);
                } catch (Throwable) {
                    // Preserve the output callback failure.
                }

                throw $exception;
            }
        };
    }
}
