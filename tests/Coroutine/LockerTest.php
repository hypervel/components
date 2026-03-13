<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Locker;
use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class LockerTest extends TestCase
{
    public function testLockAndUnlock()
    {
        $chan = new Channel(10);
        go(function () use ($chan) {
            Locker::lock('foo');
            $chan->push(1);
            usleep(10000);
            $chan->push(2);
            Locker::unlock('foo');
        });

        go(function () use ($chan) {
            Locker::lock('foo');
            $chan->push(3);
            usleep(10000);
            $chan->push(4);
        });

        go(function () use ($chan) {
            Locker::lock('foo');
            $chan->push(5);
            $chan->push(6);
        });

        $ret = [];
        for ($i = 0; $i < 6; ++$i) {
            $res = $chan->pop(0.1);
            $this->assertNotFalse($res);
            $ret[] = $res;
        }

        $this->assertSame([1, 2, 3, 5, 6, 4], $ret);
    }
}
