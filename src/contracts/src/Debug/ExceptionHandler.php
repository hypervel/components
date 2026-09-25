<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Debug;

use Hypervel\Http\Request;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @method bool shouldStopRetries(Throwable $e)
 */
interface ExceptionHandler
{
    /**
     * Report or log an exception.
     *
     * @throws Throwable
     */
    public function report(Throwable $e): void;

    /**
     * Determine if the exception should be reported.
     */
    public function shouldReport(Throwable $e): bool;

    /**
     * Render an exception into an HTTP response.
     *
     * @throws Throwable
     */
    public function render(Request $request, Throwable $e): Response;

    /**
     * Render an exception to the console.
     */
    public function renderForConsole(OutputInterface $output, Throwable $e): void;

    /**
     * Determine if a given exception is being reported.
     */
    public function isReporting(Throwable $e): bool;

    /**
     * Create the context for an exception.
     *
     * @return array<array-key, mixed>
     */
    public function buildContextForException(Throwable $e): array;

    /**
     * Register a callback to be called after an HTTP error response is rendered.
     */
    public function afterResponse(callable $callback): void;
}
