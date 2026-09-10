<?php

declare(strict_types=1);

namespace Hypervel\Database;

use PDOException;
use Throwable;

class DeadlockException extends PDOException
{
    /**
     * Create a new deadlock exception instance.
     */
    public function __construct(string $message = '', int|string $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        // Losing driver metadata makes nested concurrency failures unrecognizable to error detectors.
        $this->code = $code;

        if ($previous instanceof PDOException) {
            $this->errorInfo = $previous->errorInfo;
        }
    }
}
