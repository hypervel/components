<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Closure;
use Swoole\Coroutine\CanceledException;
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
                $cancellation = null;

                // Coroutine teardown can expose an invalid native resource through SWOOLE_HOOK_PROC.
                // Retry failed cleanup while preserving the callback failure or cancellation.
                for ($attempt = 0; $attempt < 2; ++$attempt) {
                    try {
                        $process->stop(0);

                        break;
                    } catch (CanceledException $cleanupException) {
                        $cancellation ??= $cleanupException;
                    } catch (Throwable) {
                    }
                }

                if ($cancellation !== null && ! $exception instanceof CanceledException) {
                    throw $cancellation;
                }

                throw $exception;
            }
        };
    }
}
