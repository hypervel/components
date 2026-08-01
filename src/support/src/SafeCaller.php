<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Throwable;

class SafeCaller
{
    public function __construct(private Container $container)
    {
    }

    /**
     * Execute the given closure, catching any exceptions and reporting them.
     *
     * A failing reporter must not replace the caught exception or prevent the caller's default from being returned.
     *
     * @template TReturn
     * @template TDefault
     *
     * @param Closure():TReturn $closure
     * @param null|(Closure(): TDefault) $default
     * @return ($default is Closure? TDefault : null)|TReturn
     */
    public function call(Closure $closure, ?Closure $default = null): mixed
    {
        try {
            return $closure();
        } catch (Throwable $exception) {
            if ($this->container->has(ExceptionHandlerContract::class)) {
                try {
                    $this->container->make(ExceptionHandlerContract::class)->report($exception);
                } catch (Throwable $reportingFailure) {
                    try {
                        error_log((string) $exception . PHP_EOL . (string) $reportingFailure);
                    } catch (Throwable) {
                    }
                }
            }
        }

        return value($default);
    }
}
