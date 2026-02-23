<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Events;

use Hypervel\ServerProcess\AbstractProcess;

class BeforeProcessHandle
{
    public function __construct(
        public readonly AbstractProcess $process,
        public readonly int $index,
    ) {
    }
}
