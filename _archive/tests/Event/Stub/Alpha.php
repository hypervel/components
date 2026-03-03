<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event\Stub;

use Hypervel\Event\Stoppable;
use Psr\EventDispatcher\StoppableEventInterface;

class Alpha implements StoppableEventInterface
{
    use Stoppable;
}
