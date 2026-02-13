<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\defer;
use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

/**
 * @internal
 * @coversNothing
 */
class FunctionTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testReturnOfGo()
    {
        $uniqid = uniqid();
        $id = go(function () use (&$uniqid) {
            $uniqid = 'Hypervel';
        });

        $this->assertTrue(is_int($id));
        $this->assertSame('Hypervel', $uniqid);
    }

    public function testDefer()
    {
        $channel = new Channel(10);
        parallel([function () use ($channel) {
            defer(function () use ($channel) {
                $channel->push(0);
            });
            defer(function () use ($channel) {
                $channel->push(1);
                defer(function () use ($channel) {
                    $channel->push(2);
                });
                defer(function () use ($channel) {
                    $channel->push(3);
                });
            });
            defer(function () use ($channel) {
                $channel->push(4);
            });
            $channel->push(5);
        }]);

        $this->assertSame(5, $channel->pop(0.001));
        $this->assertSame(4, $channel->pop(0.001));
        $this->assertSame(1, $channel->pop(0.001));
        $this->assertSame(3, $channel->pop(0.001));
        $this->assertSame(2, $channel->pop(0.001));
        $this->assertSame(0, $channel->pop(0.001));
    }
}
