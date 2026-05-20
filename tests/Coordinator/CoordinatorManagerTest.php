<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coordinator;

use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Tests\TestCase;

use function Hypervel\Coordinator\block;
use function Hypervel\Coroutine\go;

class CoordinatorManagerTest extends TestCase
{
    public function testFlushStateWakesWaitersAndClearsContainer()
    {
        $identifier = uniqid();
        $coordinator = CoordinatorManager::until($identifier);
        $aborted = false;

        $wg = new WaitGroup;
        $wg->add();

        go(function () use (&$aborted, $identifier, $wg) {
            $aborted = block(10, $identifier);
            $wg->done();
        });

        usleep(10000);

        CoordinatorManager::flushState();

        $wg->wait();

        $this->assertTrue($aborted);
        $this->assertNotSame($coordinator, CoordinatorManager::until($identifier));
    }
}
