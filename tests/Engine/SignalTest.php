<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Signal;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SignalTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testSignal()
    {
        $res = Signal::wait(SIGUSR1, 0.05);
        $this->assertFalse($res);

        go(static function () {
            usleep(100000);
            posix_kill(getmypid(), SIGUSR1);
        });

        $res = Signal::wait(SIGUSR1, 0.5);
        $this->assertTrue($res);
    }
}
