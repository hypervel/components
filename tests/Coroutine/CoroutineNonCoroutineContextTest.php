<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Coroutine;
use Swoole\Runtime;

use function Hypervel\Coroutine\run;

/**
 * Tests that must run outside coroutine context.
 *
 * These tests verify behavior when NOT already in a coroutine,
 * so we disable RunTestsInCoroutine's automatic coroutine wrapping.
 *
 * @internal
 * @coversNothing
 */
class CoroutineNonCoroutineContextTest extends CoroutineTestCase
{
    protected bool $enableCoroutine = false;

    public function testCoroutineInTopCoroutine(): void
    {
        run(function () {
            $this->assertSame(0, Coroutine::parentId());
        });
    }

    public function testRun(): void
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
