<?php

declare(strict_types=1);

namespace WorkerStateDependency;

use Hypervel\Support\Attributes\Unsupported;
use LogicException;

class UnsupportedReporter
{
    #[Unsupported]
    public function send(): void
    {
        throw new LogicException('Reports cannot be sent.');
    }
}
