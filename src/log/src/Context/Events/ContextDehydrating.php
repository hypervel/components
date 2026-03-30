<?php

declare(strict_types=1);

namespace Hypervel\Log\Context\Events;

use Hypervel\Log\Context\Repository;

class ContextDehydrating
{
    public function __construct(
        public Repository $context
    ) {
    }
}
