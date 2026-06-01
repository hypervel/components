<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Tests\TestCase;

class ConsoleStaticStateTest extends TestCase
{
    public function testCommandFlushStateClearsMacrosRegisteredOnSubclasses()
    {
        ConsoleStaticStateTestCommand::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(ConsoleStaticStateTestCommand::hasMacro('testingStaticStateProbe'));

        Command::flushState();

        $this->assertFalse(ConsoleStaticStateTestCommand::hasMacro('testingStaticStateProbe'));
    }

    public function testScheduleFlushStateClearsMacros()
    {
        Schedule::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(Schedule::hasMacro('testingStaticStateProbe'));

        Schedule::flushState();

        $this->assertFalse(Schedule::hasMacro('testingStaticStateProbe'));
    }
}

class ConsoleStaticStateTestCommand extends Command
{
    protected ?string $name = 'console-static-state-test-command';
}
