<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Mutex;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class MutexTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testMutexLock()
    {
        $chan = new Channel(5);
        $func = function (string $value) use ($chan) {
            if (Mutex::lock('test')) {
                try {
                    usleep(1000);
                    $chan->push($value);
                } finally {
                    Mutex::unlock('test');
                }
            }
        };

        $wg = new WaitGroup(5);
        foreach (['h', 'e', 'l', 'l', 'o'] as $value) {
            go(function () use ($func, $value, $wg) {
                $func($value);
                $wg->done();
            });
        }

        $res = '';
        $wg->wait(1);
        for ($i = 0; $i < 5; ++$i) {
            $res .= $chan->pop(1);
        }

        $this->assertSame('hello', $res);
    }
}
