<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Tracing;

use Hypervel\Support\Str;
use Sentry\Frame;
use Sentry\FrameBuilder;
use Sentry\Options;
use Sentry\Serializer\RepresentationSerializerInterface;

/**
 * @internal
 */
class BacktraceHelper
{
    /**
     * The SDK client options.
     */
    private Options $options;

    /**
     * An instance of the builder of {@see Frame} objects.
     */
    private FrameBuilder $frameBuilder;

    /**
     * Create a new backtrace helper instance.
     *
     * @param Options $options The SDK client options
     * @param RepresentationSerializerInterface $representationSerializer The representation serializer
     */
    public function __construct(Options $options, RepresentationSerializerInterface $representationSerializer)
    {
        $this->options = $options;
        $this->frameBuilder = new FrameBuilder($options, $representationSerializer);
    }

    /**
     * Find the first in app frame for a given backtrace.
     *
     * @param array<int, array<string, mixed>> $backtrace The backtrace
     *
     * @phpstan-param list<array{
     *     line?: integer,
     *     file?: string,
     * }> $backtrace
     */
    public function findFirstInAppFrameForBacktrace(array $backtrace): ?Frame
    {
        $file = Frame::INTERNAL_FRAME_FILENAME;
        $line = 0;

        foreach ($backtrace as $backtraceFrame) {
            $frame = $this->frameBuilder->buildFromBacktraceFrame($file, $line, $backtraceFrame);

            if ($frame->isInApp()) {
                return $frame;
            }

            $file = $backtraceFrame['file'] ?? Frame::INTERNAL_FRAME_FILENAME;
            $line = $backtraceFrame['line'] ?? 0;
        }

        return null;
    }

    /**
     * Take a frame and, if it's a compiled view path, return the original view path.
     */
    public function getOriginalViewPathForFrameOfCompiledViewPath(Frame $frame): ?string
    {
        $absoluteFilePath = $frame->getAbsoluteFilePath();

        if ($absoluteFilePath === null) {
            return null;
        }

        // Frames contain resolved paths, even when the configured cache directory is a symlink.
        $compiledDirectory = config()->string('view.compiled');
        $compiledDirectory = realpath($compiledDirectory) ?: $compiledDirectory;

        if (! str_starts_with($absoluteFilePath, rtrim($compiledDirectory, '/\\') . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $viewFileContents = @file_get_contents($absoluteFilePath);

        // If for some reason the file does not exist, skip resolving
        if ($viewFileContents === false) {
            return null;
        }

        // The compiler writes the footer last; a template can contain the same text.
        preg_match('/.*\/\*\*PATH (?<originalPath>.*) ENDPATH/s', $viewFileContents, $matches);

        // No path comment found in the file
        if (empty($matches['originalPath'])) {
            return null;
        }

        return $this->stripPrefixFromFilePath($matches['originalPath']);
    }

    /**
     * Remove the specified prefixes from the given file path.
     *
     * @param string $filePath The path to the file
     */
    private function stripPrefixFromFilePath(string $filePath): string
    {
        foreach ($this->options->getPrefixes() as $prefix) {
            if (Str::startsWith($filePath, $prefix)) {
                return mb_substr($filePath, mb_strlen($prefix));
            }
        }

        return $filePath;
    }
}
