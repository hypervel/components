<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool\Stub;

use Hypervel\ObjectPool\ObjectPool;
use stdClass;

class FooPool extends ObjectPool
{
    protected function createObject(): object
    {
        return new stdClass();
    }
}
