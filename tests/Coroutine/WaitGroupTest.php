<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\WaitGroup;
use Swoole\Coroutine;

/**
 * @internal
 * @coversNothing
 */
class WaitGroupTest extends CoroutineTestCase
{
    public function testWaitAgain(): void
    {
        $wg = new WaitGroup();
        $wg->add(2);
        $result = [];
        $i = 2;
        while ($i--) {
            Coroutine::create(function () use ($wg, &$result) {
                Coroutine::sleep(0.001);
                $result[] = true;
                $wg->done();
            });
        }
        $wg->wait(1);
        $this->assertTrue(count($result) === 2);

        $wg->add();
        $wg->add();
        $result = [];
        $i = 2;
        while ($i--) {
            Coroutine::create(function () use ($wg, &$result) {
                Coroutine::sleep(0.001);
                $result[] = true;
                $wg->done();
            });
        }
        $wg->wait(1);
        $this->assertTrue(count($result) === 2);
    }
}
