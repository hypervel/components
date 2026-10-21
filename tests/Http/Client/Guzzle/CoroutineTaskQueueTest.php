<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Guzzle;

use GuzzleHttp\Promise\TaskQueue;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Http\Client\Guzzle\CoroutineTaskQueue;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class CoroutineTaskQueueTest extends TestCase
{
    public function testYieldingDrainsKeepTaskOrderAndCoroutineOwnership(): void
    {
        $queue = new CoroutineTaskQueue(new TaskQueue(false));
        $observed = $owners = $callbacks = [];

        foreach (['first', 'second'] as $name) {
            $callbacks[] = static function () use ($queue, $name, &$owners, &$observed): void {
                $owners[$name] = Coroutine::id();
                $queue->add(static function () use ($name, &$observed): void {
                    usleep(1000);
                    $observed[$name][] = [1, Coroutine::id()];
                });
                $queue->add(static function () use ($name, &$observed): void {
                    $observed[$name][] = [2, Coroutine::id()];
                });
                $queue->run();
            };
        }

        parallel($callbacks);

        foreach ($owners as $name => $owner) {
            $this->assertSame([[1, $owner], [2, $owner]], $observed[$name]);
        }
    }

    #[DataProvider('copyModes')]
    public function testChildrenDoNotInheritPendingTasks(string $mode): void
    {
        $queue = new CoroutineTaskQueue(new TaskQueue(false));
        $parentRan = false;
        $queue->add(static function () use (&$parentRan): void {
            $parentRan = true;
        });
        $done = new Channel(1);
        $child = static function () use ($queue, $done): void {
            $empty = $queue->isEmpty();
            $queue->run();
            $done->push($empty);
        };

        match ($mode) {
            'fork' => Coroutine::fork($child),
            'forkOwned' => Coroutine::forkOwned($child, static function (callable $operation): void {
                $operation();
            }),
            'parallel' => parallel([$child], copyContext: true),
        };

        $this->assertTrue($done->pop(1));
        $this->assertFalse($parentRan);
        $queue->run();
        $this->assertTrue($parentRan);
    }

    /**
     * Provide supported child-creation paths that copy coroutine context.
     */
    public static function copyModes(): array
    {
        return [['fork'], ['forkOwned'], ['parallel']];
    }

    public function testCoroutineExitRunsAndReleasesCallbacksInTheirOwner(): void
    {
        $queue = new CoroutineTaskQueue(new TaskQueue(false));
        $observed = null;
        [[$reference, $expected]] = parallel([
            static function () use ($queue, &$observed): array {
                $retained = new stdClass;
                $queue->add(static function () use ($retained, &$observed): void {
                    $observed = [spl_object_id($retained), Coroutine::id()];
                });

                return [WeakReference::create($retained), [spl_object_id($retained), Coroutine::id()]];
            },
        ]);

        $queue->run();

        $this->assertSame($expected, $observed);
        $this->assertNull($reference->get());
    }
}
