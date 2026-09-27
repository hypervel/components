<?php

declare(strict_types=1);

namespace Hypervel\Process\Exceptions;

use Hypervel\Contracts\Process\ProcessResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Exception\RuntimeException;

class ProcessTimedOutException extends RuntimeException
{
    /**
     * The process result instance.
     */
    public ProcessResult $result;

    /**
     * The original Symfony exception instance.
     */
    protected SymfonyTimeoutException $original;

    /**
     * Create a new exception instance.
     */
    public function __construct(SymfonyTimeoutException $original, ProcessResult $result)
    {
        $this->result = $result;
        $this->original = $original;

        parent::__construct($original->getMessage(), $original->getCode(), $original);
    }

    /**
     * Create a new exception instance for the type of timeout that occurred.
     */
    public static function make(SymfonyTimeoutException $original, ProcessResult $result): ProcessTimedOutException
    {
        return $original->isIdleTimeout()
            ? new ProcessIdleTimedOutException($original, $result)
            : new ProcessTimedOutException($original, $result);
    }

    /**
     * Get the number of seconds the process was allowed to run before timing out.
     */
    public function exceededTimeout(): ?float
    {
        return $this->original->getExceededTimeout();
    }
}
