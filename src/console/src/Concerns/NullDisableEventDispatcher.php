<?php

declare(strict_types=1);

namespace Hypervel\Console\Concerns;

use Symfony\Component\Console\Input\InputInterface;

trait NullDisableEventDispatcher
{
    public function addDisableDispatcherOption(): void
    {
    }

    public function disableDispatcher(InputInterface $input): void
    {
    }
}
