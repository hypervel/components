<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hyperf\Collection\Collection;
use Hyperf\Stringable\Str;
use Throwable;

class ExceptionContext
{
    /**
     * Get the exception code context for the given exception.
     */
    public static function get(Throwable $exception): array
    {
        return static::getEvalContext($exception)
            ?? static::getFileContext($exception);
    }

    /**
     * Get the exception code context when eval() failed.
     */
    protected static function getEvalContext(Throwable $exception): ?array
    {
        if (Str::contains($exception->getFile(), "eval()'d code")) {
            return [
                $exception->getLine() => "eval()'d code",
            ];
        }

        return null;
    }

    /**
     * Get the exception code context from a file.
     */
    protected static function getFileContext(Throwable $exception): array
    {
        return Collection::make(explode("\n", file_get_contents($exception->getFile())))
            ->slice($exception->getLine() - 10, 20)
            ->mapWithKeys(function ($value, $key) {
                return [$key + 1 => $value];
            })->all();
    }
}
