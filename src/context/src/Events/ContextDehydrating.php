<?php

declare(strict_types=1);

namespace Hypervel\Context\Events;

use Hypervel\Context\PropagatedContext;

class ContextDehydrating
{
    public function __construct(
        public PropagatedContext $context
    ) {
    }
}
