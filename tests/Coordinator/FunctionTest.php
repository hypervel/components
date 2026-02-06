<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coordinator;

use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

use function Hypervel\Coordinator\block;
use function Hypervel\Coordinator\resume;
use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class FunctionTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();

        // RunTestsInCoroutine resumes the WORKER_EXIT coordinator after each test,
        // closing its channel. Clear it so each test gets a fresh coordinator.
        CoordinatorManager::clear(Constants::WORKER_EXIT);
    }

    public function testBlock()
    {
        $aborted = block(0.001);
        $this->assertFalse($aborted);
    }

    public function testBlockMicroSeconds()
    {
        $aborted = block(0.000001);
        $this->assertFalse($aborted);
    }

    public function testResume()
    {
        $identifier = uniqid();
        $wg = new WaitGroup();
        $wg->add();
        go(function () use ($wg, $identifier) {
            $aborted = block(10, $identifier);
            $this->assertTrue($aborted);
            $wg->done();
        });
        $wg->add();
        go(function () use ($wg, $identifier) {
            $aborted = block(10, $identifier);
            $this->assertTrue($aborted);
            $wg->done();
        });
        resume($identifier);
        $wg->wait();
    }
}
