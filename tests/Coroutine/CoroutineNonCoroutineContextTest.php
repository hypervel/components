<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Tests\TestCase;
use stdClass;
use Swoole\Runtime;
use TypeError;

use function Hypervel\Coroutine\run;

/**
 * Tests that must run outside coroutine context.
 */
class CoroutineNonCoroutineContextTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

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

    public function testRunSupportsCallableArraysAndRestoresHookFlags(): void
    {
        $originalFlags = Runtime::getHookFlags();
        Runtime::enableCoroutine(SWOOLE_HOOK_SLEEP);

        try {
            $target = new CoroutineRunCallableTarget;

            $this->assertTrue(run([$target, 'capture'], SWOOLE_HOOK_CURL));
            $this->assertSame(SWOOLE_HOOK_CURL, $target->flags);
            $this->assertSame(SWOOLE_HOOK_SLEEP, Runtime::getHookFlags());

            $argument = null;
            $flags = null;

            $this->assertTrue(run([
                static function (string $value) use (&$argument, &$flags): void {
                    $argument = $value;
                    $flags = Runtime::getHookFlags();
                },
                'tuple argument',
            ], SWOOLE_HOOK_FILE));
            $this->assertSame('tuple argument', $argument);
            $this->assertSame(SWOOLE_HOOK_FILE, $flags);
            $this->assertSame(SWOOLE_HOOK_SLEEP, Runtime::getHookFlags());

            try {
                run([new stdClass], SWOOLE_HOOK_ALL);
                $this->fail('Expected the invalid callback tuple to fail.');
            } catch (TypeError) {
                $this->assertSame(SWOOLE_HOOK_SLEEP, Runtime::getHookFlags());
            }
        } finally {
            Runtime::enableCoroutine($originalFlags);
        }
    }
}

class CoroutineRunCallableTarget
{
    public ?int $flags = null;

    public function capture(): void
    {
        $this->flags = Runtime::getHookFlags();
    }
}
