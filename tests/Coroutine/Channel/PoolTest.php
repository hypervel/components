<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine\Channel;

use Hypervel\Coroutine\Channel\Pool;
use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;

class PoolTest extends TestCase
{
    public function testFlushStateClearsReleasedChannelsAndResetsSingleton()
    {
        try {
            $pool = Pool::getInstance();
            $channel = new Channel(1);
            $pool->release($channel);

            Pool::flushState();

            $freshPool = Pool::getInstance();

            $this->assertNotSame($pool, $freshPool);
            $this->assertNotSame($channel, $freshPool->get());
        } finally {
            Pool::flushState();
        }
    }
}
