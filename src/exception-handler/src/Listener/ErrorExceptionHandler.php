<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler\Listener;

use ErrorException;
use Hypervel\Framework\Events\BootApplication;

class ErrorExceptionHandler
{
    /**
     * Register the error handler that converts errors to exceptions.
     */
    public function handle(BootApplication $event): void
    {
        set_error_handler(static function ($level, $message, $file = '', $line = 0): bool {
            if (error_reporting() & $level) {
                throw new ErrorException($message, 0, $level, $file, $line);
            }

            return true;
        });
    }
}
