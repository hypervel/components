<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Concerns;

use Hypervel\Contracts\Container\Container;
use Hypervel\Sentry\Tracing\BacktraceHelper;

trait ResolvesEventOrigin
{
    /**
     * Retrieve the application container.
     */
    protected function container(): Container
    {
        return app();
    }

    /**
     * Resolve the code location of the first application frame of the event.
     *
     * @param null|array $backtrace a backtrace captured earlier, for an event whose origin is no longer on the stack
     */
    protected function resolveEventOrigin(?array $backtrace = null): ?array
    {
        $backtraceHelper = $this->makeBacktraceHelper();

        // We limit the backtrace to 20 frames to prevent too much overhead and we'd reasonable expect the origin to be within the first 20 frames
        $firstAppFrame = $backtraceHelper->findFirstInAppFrameForBacktrace(
            $backtrace ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20)
        );

        if ($firstAppFrame === null) {
            return null;
        }

        $filePath = $backtraceHelper->getOriginalViewPathForFrameOfCompiledViewPath(
            $firstAppFrame
        ) ?? $firstAppFrame->getFile();

        return [
            'code.filepath' => $filePath,
            'code.function' => $firstAppFrame->getFunctionName(),
            'code.lineno' => $firstAppFrame->getLine(),
        ];
    }

    /**
     * Resolve the code location of the event as a "file:line" string.
     */
    protected function resolveEventOriginAsString(): ?string
    {
        $origin = $this->resolveEventOrigin();

        if ($origin === null) {
            return null;
        }

        return "{$origin['code.filepath']}:{$origin['code.lineno']}";
    }

    /**
     * Resolve the backtrace helper from the container.
     */
    private function makeBacktraceHelper(): BacktraceHelper
    {
        return $this->container()->make(BacktraceHelper::class);
    }
}
