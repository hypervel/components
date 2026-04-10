<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Configuration;

use Hypervel\Console\Commands\ScheduleListCommand;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Support\Carbon;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class WithScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2023-01-01');
        ScheduleListCommand::resolveTerminalWidthUsing(fn () => 80);
    }

    protected function resolveApplication(): ApplicationContract
    {
        return Application::configure(static::applicationBasePath())
            ->withSchedule(function ($schedule) {
                $schedule->command('schedule:clear-cache')->everyMinute();
            })
            ->withCommands([__DIR__ . '/Fixtures/console.php'])
            ->create();
    }

    public function testDisplaySchedule()
    {
        $this->artisan(ScheduleListCommand::class)
            ->assertSuccessful()
            ->expectsOutputToContain('  0 * * * *  php artisan test:inspire .............. Next Due: 1 hour from now')
            ->expectsOutputToContain('  * * * * *  php artisan schedule:clear-cache .... Next Due: 1 minute from now');
    }
}
