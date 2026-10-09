<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Guzzle;

use GuzzleHttp\Promise\TaskQueue;
use GuzzleHttp\Promise\Utils;
use Hypervel\Http\Client\Guzzle\CoroutineTaskQueue;
use Hypervel\Tests\TestCase;
use stdClass;
use WeakReference;

use function Hypervel\Coroutine\run;

class CoroutineTaskQueueNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testRepeatedInstallationPreservesTheOriginalOutsideQueue(): void
    {
        $previous = Utils::queue();
        $outside = new TaskQueue(false);
        $ran = [];
        $outside->add(static function () use (&$ran): void {
            $ran[] = 'outside';
        });
        Utils::queue($outside);

        try {
            CoroutineTaskQueue::install();
            $installed = Utils::queue();
            CoroutineTaskQueue::install();
            $this->assertSame($installed, Utils::queue());

            run(static function () use ($installed, &$ran): void {
                $installed->add(static function () use (&$ran): void {
                    $ran[] = 'inside';
                });
                $installed->run();
            });

            $this->assertSame(['inside'], $ran);
            $installed->run();
            $this->assertSame(['inside', 'outside'], $ran);
            $this->assertTrue($outside->isEmpty());
        } finally {
            Utils::queue($previous);
        }
    }

    public function testTestResetReleasesAbandonedTasksWithoutRunningThem(): void
    {
        $queue = new CoroutineTaskQueue(new TaskQueue(false));
        $retained = new stdClass;
        $reference = WeakReference::create($retained);
        $observed = null;
        $queue->add(static function () use ($retained, &$observed): void {
            $observed = $retained;
        });
        unset($retained);

        $this->assertNotNull($reference->get());
        $queue->resetOutside();
        $queue->run();

        $this->assertNull($observed);
        $this->assertNull($reference->get());
    }
}
