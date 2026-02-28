<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Commands\ScheduleClearCacheCommand;
use Hypervel\Console\Commands\ScheduleListCommand;
use Hypervel\Console\Commands\ScheduleRunCommand;
use Hypervel\Console\Commands\ScheduleStopCommand;
use Hypervel\Console\Commands\ScheduleTestCommand;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ConsoleServiceProviderTest extends TestCase
{
    public function testScheduleCommandsAreRegistered()
    {
        $kernel = $this->app->make(KernelContract::class);
        $artisan = $kernel->getArtisan();

        $expectedCommands = [
            'schedule:clear-cache' => ScheduleClearCacheCommand::class,
            'schedule:list' => ScheduleListCommand::class,
            'schedule:run' => ScheduleRunCommand::class,
            'schedule:stop' => ScheduleStopCommand::class,
            'schedule:test' => ScheduleTestCommand::class,
        ];

        foreach ($expectedCommands as $name => $class) {
            $this->assertTrue($artisan->has($name), "Command '{$name}' should be registered");
            $this->assertInstanceOf($class, $artisan->find($name));
        }
    }
}
