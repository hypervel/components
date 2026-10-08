<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Utils;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\run;

class CoroutineTaskQueueNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testTasksQueuedOutsideCoroutinesStayOutsideThem(): void
    {
        $ran = false;
        $promise = Create::promiseFor(1)->then(static function (int $value) use (&$ran): int {
            $ran = true;

            return $value + 1;
        });

        try {
            $coroutine = [];
            run(static function () use (&$coroutine, &$ran): void {
                $coroutine['saw tasks'] = ! Utils::queue()->isEmpty();
                $coroutine['result'] = Create::promiseFor(2)->then(static fn (int $value): int => $value + 1)->wait();
                $coroutine['ran outside task'] = $ran;
            });

            $this->assertSame(['saw tasks' => false, 'result' => 3, 'ran outside task' => false], $coroutine);
            $this->assertSame(2, $promise->wait());
            $this->assertTrue($ran);
        } finally {
            Utils::queue()->run();
        }
    }
}
