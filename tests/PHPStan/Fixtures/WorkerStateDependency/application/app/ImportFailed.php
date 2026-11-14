<?php

declare(strict_types=1);

namespace WorkerStateDependency;

use RuntimeException;
use Throwable;

class ImportFailed extends RuntimeException
{
    public function __construct(Throwable $cause)
    {
        parent::__construct('Import failed.', 0, $cause);
    }
}
