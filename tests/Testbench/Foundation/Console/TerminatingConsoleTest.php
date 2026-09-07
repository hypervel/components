<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Testbench\Foundation\Console\TerminatingConsole;
use Hypervel\Testbench\PHPUnit\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class TerminatingConsoleTest extends TestCase
{
    #[Test]
    public function itCanHandleTerminatingCallbacksOnTerminal(): void
    {
        $this->assertFalse(isset($_SERVER['TerminatingConsole.before']));
        $this->assertFalse(isset($_SERVER['TerminatingConsole.beforeWhenTrue']));
        $this->assertFalse(isset($_SERVER['TerminatingConsole.beforeWhenFalse']));

        TerminatingConsole::before(function () {
            $_SERVER['TerminatingConsole.before'] = true;
        });

        TerminatingConsole::beforeWhen(true, function () {
            $_SERVER['TerminatingConsole.beforeWhenTrue'] = true;
        });

        TerminatingConsole::beforeWhen(false, function () {
            $_SERVER['TerminatingConsole.beforeWhenFalse'] = true;
        });

        TerminatingConsole::handle();

        $this->assertTrue(isset($_SERVER['TerminatingConsole.before']));
        $this->assertTrue(isset($_SERVER['TerminatingConsole.beforeWhenTrue']));
        $this->assertFalse(isset($_SERVER['TerminatingConsole.beforeWhenFalse']));

        unset(
            $_SERVER['TerminatingConsole.before'],
            $_SERVER['TerminatingConsole.beforeWhenTrue'],
            $_SERVER['TerminatingConsole.beforeWhenFalse'],
        );

        TerminatingConsole::flush();
    }

    #[Test]
    public function itExhaustsDetachedCallbacksAndDoesNotReplayReentrantRegistrations(): void
    {
        $calls = [];
        $failure = new RuntimeException('termination failed');

        TerminatingConsole::before(function () use (&$calls): void {
            $calls[] = 'first';
        });
        TerminatingConsole::before(function () use (&$calls, $failure): never {
            $calls[] = 'second';
            TerminatingConsole::before(function () use (&$calls): void {
                $calls[] = 'reentrant';
            });

            throw $failure;
        });

        try {
            TerminatingConsole::handle();
            $this->fail('Expected the first terminating failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(['second', 'first'], $calls);

        TerminatingConsole::handle();

        $this->assertSame(['second', 'first'], $calls);
    }
}
