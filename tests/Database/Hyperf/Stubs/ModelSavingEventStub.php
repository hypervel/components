<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Psr\EventDispatcher\StoppableEventInterface;

class ModelSavingEventStub implements StoppableEventInterface
{
    public function __construct($model = null)
    {
    }

    public function isPropagationStopped(): bool
    {
        return true;
    }
}
