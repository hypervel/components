<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Foundation\Exceptions\Handler as BaseExceptionHandler;
use Hypervel\Http\Request;
use Throwable;

class ExceptionHandler extends BaseExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected array $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return true;
        });

        $this->reportable(function (Throwable $e) {});
    }
}
