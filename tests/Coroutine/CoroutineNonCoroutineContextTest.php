<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Tests\TestCase;
use Swoole\Runtime;

use function Hypervel\Coroutine\run;

/**
 * Tests that must run outside coroutine context.
 *
 * @internal
 * @coversNothing
 */
class CoroutineNonCoroutineContextTest extends TestCase
{
    public function testCoroutineInTopCoroutine()
    {
        run(function () {
            $this->assertSame(0, Coroutine::parentId());
        });
    }

    public function testRun()
    {
        $asserts = [
            SWOOLE_HOOK_ALL,
            SWOOLE_HOOK_SLEEP,
            SWOOLE_HOOK_CURL,
        ];

        foreach ($asserts as $flags) {
            run(function () use ($flags) {
                $this->assertTrue(Coroutine::inCoroutine());
                $this->assertSame($flags, Runtime::getHookFlags());
            }, $flags);
        }
    }
}
