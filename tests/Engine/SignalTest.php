<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Signal;

/**
 * @internal
 * @coversNothing
 */
class SignalTest extends EngineTestCase
{
    public function testSignal()
    {
        $res = Signal::wait(SIGUSR1, 1);
        $this->assertFalse($res);

        go(static function () {
            sleep(1);
            posix_kill(getmypid(), SIGUSR1);
        });

        $res = Signal::wait(SIGUSR1, 2);
        $this->assertTrue($res);
    }
}
