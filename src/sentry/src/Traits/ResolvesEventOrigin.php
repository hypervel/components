<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Traits;

use Hypervel\Sentry\Tracing\BacktraceHelper;

trait ResolvesEventOrigin
{
    protected function resolveEventOrigin(): ?array
    {
        $backtraceHelper = $this->getBacktraceHelper();

        // We limit the backtrace to 20 frames to prevent too much overhead and we'd reasonable expect the origin to be within the first 20 frames
        $firstAppFrame = $backtraceHelper->findFirstInAppFrameForBacktrace(
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20)
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

    protected function resolveEventOriginAsString(): ?string
    {
        $origin = $this->resolveEventOrigin();

        if ($origin === null) {
            return null;
        }

        return "{$origin['code.filepath']}:{$origin['code.lineno']}";
    }

    private function getBacktraceHelper(): BacktraceHelper
    {
        return $this->container()->get(BacktraceHelper::class);
    }
}
