<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Concerns;

/**
 * Resolve dump sources for consumers that provide $basePath and $compiledViewPath properties.
 */
trait ResolvesDumpSource
{
    use ResolvesSourceHref;

    /**
     * Files that require special trace handling and their levels.
     *
     * @var array<string, int>
     */
    protected static array $adjustableTraces = [
        'symfony/var-dumper/Resources/functions/dump.php' => 1,
        'collections/src/Traits/EnumeratesValues.php' => 4,
    ];

    /**
     * The source resolver.
     *
     * @var null|(callable(): (null|array{0: string, 1: string, 2: null|int}))|false
     */
    protected static $dumpSourceResolver;

    /**
     * Resolve the source of the dump call.
     *
     * @return null|array{0: string, 1: string, 2: null|int}
     */
    public function resolveDumpSource(): ?array
    {
        if (static::$dumpSourceResolver === false) {
            return null;
        }

        if (static::$dumpSourceResolver) {
            return call_user_func(static::$dumpSourceResolver);
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        $sourceKey = null;

        foreach ($trace as $traceKey => $traceFile) {
            if (! isset($traceFile['file'])) {
                continue;
            }

            foreach (self::$adjustableTraces as $name => $key) {
                if (str_ends_with(
                    $traceFile['file'],
                    str_replace('/', DIRECTORY_SEPARATOR, $name)
                )) {
                    $sourceKey = $traceKey + $key;
                    break;
                }
            }

            if (! is_null($sourceKey)) {
                break;
            }
        }

        if (is_null($sourceKey)) {
            return null;
        }

        $file = $trace[$sourceKey]['file'] ?? null;
        $line = $trace[$sourceKey]['line'] ?? null;

        if (is_null($file) || is_null($line)) {
            return null;
        }

        $relativeFile = $file;

        if ($this->isCompiledViewFile($file)) {
            $file = $this->getOriginalFileForCompiledView($file);
            $line = null;
        }

        if (str_starts_with($file, $this->basePath)) {
            $relativeFile = substr($file, strlen($this->basePath) + 1);
        }

        return [$file, $relativeFile, $line];
    }

    /**
     * Determine if the given file is a view compiled.
     */
    protected function isCompiledViewFile(string $file): bool
    {
        if (! $this->compiledViewPath) {
            return false;
        }

        return str_starts_with($file, $this->compiledViewPath) && str_ends_with($file, '.php');
    }

    /**
     * Get the original view compiled file by the given compiled file.
     */
    protected function getOriginalFileForCompiledView(string $file): string
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            return $file;
        }

        preg_match('/\/\*\*PATH\s(.*)\sENDPATH/', $contents, $matches);

        if (isset($matches[1])) {
            $file = $matches[1];
        }

        return $file;
    }

    /**
     * Set the resolver that resolves the source of the dump call.
     *
     * Boot-only. The resolver persists in a static property for the worker
     * lifetime and runs on every dump source resolution across all coroutines.
     *
     * @param null|(callable(): (null|array{0: string, 1: string, 2: null|int})) $callable
     */
    public static function resolveDumpSourceUsing(?callable $callable): void
    {
        static::$dumpSourceResolver = $callable;
    }

    /**
     * Don't include the location / file of the dump in dumps.
     *
     * Boot-only. The flag persists in a static property for the worker lifetime
     * and applies to every dump source resolution across all coroutines.
     */
    public static function dontIncludeSource(): void
    {
        static::$dumpSourceResolver = false;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$dumpSourceResolver = null;
    }
}
