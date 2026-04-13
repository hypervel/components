<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Testbench\Foundation\Console\TerminatingConsole;
use Hypervel\Testbench\PHPUnit\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class TerminatingConsoleTest extends TestCase
{
    #[Test]
    public function itCanHandleTerminatingCallbacksOnTerminal()
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
}
