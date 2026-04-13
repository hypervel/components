<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use ErrorException;

trait InteractsWithDeprecationHandling
{
    /**
     * The original deprecation handler.
     */
    protected mixed $originalDeprecationHandler = null;

    /**
     * Restore deprecation handling.
     */
    protected function withDeprecationHandling(): static
    {
        if ($this->originalDeprecationHandler) {
            set_error_handler(tap($this->originalDeprecationHandler, fn () => $this->originalDeprecationHandler = null));
        }

        return $this;
    }

    /**
     * Disable deprecation handling for the test.
     *
     * @throws ErrorException
     */
    protected function withoutDeprecationHandling(): static
    {
        if ($this->originalDeprecationHandler === null) {
            $this->originalDeprecationHandler = set_error_handler(function ($level, $message, $file = '', $line = 0) {
                if (in_array($level, [E_DEPRECATED, E_USER_DEPRECATED]) || (error_reporting() & $level)) {
                    throw new ErrorException($message, 0, $level, $file, $line);
                }
            });
        }

        return $this;
    }
}
