<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler\Listener;

use ErrorException;
use Hypervel\Event\Contracts\ListenerInterface;
use Hypervel\Framework\Events\BootApplication;

class ErrorExceptionHandler implements ListenerInterface
{
    /**
     * Get the events the listener should listen for.
     */
    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    /**
     * Handle the event.
     */
    public function process(object $event): void
    {
        set_error_handler(static function ($level, $message, $file = '', $line = 0): bool {
            if (error_reporting() & $level) {
                throw new ErrorException($message, 0, $level, $file, $line);
            }

            return true;
        });
    }
}
