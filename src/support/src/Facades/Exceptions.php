<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Support\Arr;
use Hypervel\Support\Testing\Fakes\ExceptionHandlerFake;

/**
 * @method static void report(\Throwable $e)
 * @method static bool shouldReport(\Throwable $e)
 * @method static \Psr\Http\Message\ResponseInterface render(\Hypervel\Http\Request $request, \Throwable $e)
 * @method static void afterResponse(callable $callback)
 * @method static \Hypervel\Foundation\Exceptions\ReportableHandler reportable(callable $reportUsing)
 * @method static \Hypervel\Foundation\Exceptions\Handler renderable(callable $renderUsing)
 * @method static \Hypervel\Foundation\Exceptions\Handler map(\Closure|string $from, \Closure|string|null $to = null)
 * @method static \Hypervel\Foundation\Exceptions\Handler dontReport(array|string $exceptions)
 * @method static \Hypervel\Foundation\Exceptions\Handler ignore(array|string $exceptions)
 * @method static \Hypervel\Foundation\Exceptions\Handler dontFlash(array|string $attributes)
 * @method static \Hypervel\Foundation\Exceptions\Handler level(string $type, string $level)
 * @method static \Hypervel\Foundation\Exceptions\Handler stopIgnoring(array|string $exceptions)
 * @method static \Hypervel\Foundation\Exceptions\Handler buildContextUsing(\Closure $contextCallback)
 * @method static \Hypervel\Foundation\Exceptions\Handler respondUsing(callable $callback)
 * @method static \Hypervel\Foundation\Exceptions\Handler shouldRenderJsonWhen(callable $callback)
 * @method static \Hypervel\Foundation\Exceptions\Handler dontReportDuplicates()
 * @method static \Hypervel\Contracts\Debug\ExceptionHandler handler()
 * @method static void assertReported(\Closure|string $exception)
 * @method static void assertReportedCount(int $count)
 * @method static void assertNotReported(\Closure|string $exception)
 * @method static void assertNothingReported()
 * @method static \Hypervel\Support\Testing\Fakes\ExceptionHandlerFake throwOnReport()
 * @method static \Hypervel\Support\Testing\Fakes\ExceptionHandlerFake throwFirstReported()
 * @method static array reported()
 * @method static \Hypervel\Support\Testing\Fakes\ExceptionHandlerFake setHandler(\Hypervel\Contracts\Debug\ExceptionHandler $handler)
 *
 * @see \Hypervel\Foundation\Exceptions\Handler
 * @see \Hypervel\Support\Testing\Fakes\ExceptionHandlerFake
 */
class Exceptions extends Facade
{
    /**
     * Replace the bound instance with a fake.
     *
     * @param array<int, class-string<\Throwable>>|class-string<\Throwable> $exceptions
     */
    public static function fake(array|string $exceptions = []): ExceptionHandlerFake
    {
        $exceptionHandler = static::isFake()
            ? static::getFacadeRoot()->handler()
            : static::getFacadeRoot();

        return tap(new ExceptionHandlerFake($exceptionHandler, Arr::wrap($exceptions)), function ($fake) {
            static::swap($fake);
        });
    }

    protected static function getFacadeAccessor(): string
    {
        return ExceptionHandler::class;
    }
}
