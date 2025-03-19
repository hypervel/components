<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions\Contracts;

use Hypervel\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Throwable;

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
    public function render(Request $request, Throwable $e): ResponseInterface;
}
