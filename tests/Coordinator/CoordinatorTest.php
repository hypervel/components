<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coordinator;

use Hypervel\Coordinator\Coordinator;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\go;

class CoordinatorTest extends TestCase
{
    public function testYield(): void
    {
        $coord = new Coordinator;
        $aborted = $coord->yield(0.001);
        $this->assertFalse($aborted);
    }

    public function testYieldMicroSeconds(): void
    {
        $coord = new Coordinator;
        $aborted = $coord->yield(0.000001);
        $this->assertFalse($aborted);
    }

    public function testYieldResume(): void
    {
        $coord = new Coordinator;
        $wg = new WaitGroup;
        $results = [];
        $wg->add();
        go(function () use ($coord, $wg, &$results): void {
            try {
                $results[] = $coord->yield(10);
            } finally {
                $wg->done();
            }
        });
        $wg->add();
        go(function () use ($coord, $wg, &$results): void {
            try {
                $results[] = $coord->yield(10);
            } finally {
                $wg->done();
            }
        });
        $coord->resume();
        $wg->wait();

        $this->assertSame([true, true], $results);
    }
}
