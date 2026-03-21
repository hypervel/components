<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Exceptions;

use Exception;

class PHPUnitErrorException extends \PHPUnit\Framework\Exception
{
    public function __construct(string $message, int $code, string $file, int $line, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->file = $file;
        $this->line = $line;
    }

    /**
     * Get serializable trace for PHPUnit.
     *
     * @codeCoverageIgnore
     */
    public function getPHPUnitExceptionTrace(): array
    {
        return $this->serializableTrace;
    }
}
