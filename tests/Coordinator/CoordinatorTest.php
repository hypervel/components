<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coordinator;

use Hypervel\Coordinator\Coordinator;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class CoordinatorTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testYield()
    {
        $coord = new Coordinator();
        $aborted = $coord->yield(0.001);
        $this->assertFalse($aborted);
    }

    public function testYieldMicroSeconds()
    {
        $coord = new Coordinator();
        $aborted = $coord->yield(0.000001);
        $this->assertFalse($aborted);
    }

    public function testYieldResume()
    {
        $coord = new Coordinator();
        $wg = new WaitGroup();
        $wg->add();
        go(function () use ($coord, $wg) {
            $aborted = $coord->yield(10);
            $this->assertTrue($aborted);
            $wg->done();
        });
        $wg->add();
        go(function () use ($coord, $wg) {
            $aborted = $coord->yield(10);
            $this->assertTrue($aborted);
            $wg->done();
        });
        $coord->resume();
        $wg->wait();
    }
}
