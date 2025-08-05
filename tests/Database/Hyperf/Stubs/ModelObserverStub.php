<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hypervel\Database\Eloquent\Events\Updating;

class ModelObserverStub
{
    public function updating(Updating $event)
    {
        $event->getModel()->foo = 'bar';
    }
}
