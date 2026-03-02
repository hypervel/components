<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling\ScheduleTestCommandTest;

use Hypervel\Console\Command;
use Hypervel\Console\Commands\ScheduleTestCommand;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ScheduleTestCommandTest extends TestCase
{
    public Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(now()->startOfYear());

        $this->schedule = $this->app->make(Schedule::class);

        Artisan::registerCommand(new BarCommandStub());
    }

    public function testRunNoDefinedCommands()
    {
        $this->artisan(ScheduleTestCommand::class)
            ->assertSuccessful()
            ->expectsOutputToContain('No scheduled commands have been defined.');
    }

    public function testRunNoMatchingCommand()
    {
        $this->schedule->command(BarCommandStub::class);

        $this->artisan(ScheduleTestCommand::class, ['--name' => 'missing:command'])
            ->assertSuccessful()
            ->expectsOutputToContain('No matching scheduled command found.');
    }

    public function testRunUsingNameOption()
    {
        $this->schedule->command(BarCommandStub::class)->name('bar-command');
        $this->schedule->job(BarJobStub::class);
        $this->schedule->call(fn () => true)->name('callback');

        $this->artisan(ScheduleTestCommand::class, ['--name' => 'bar:command'])
            ->assertSuccessful()
            ->expectsOutputToContain('Running [php artisan bar:command]');

        $this->artisan(ScheduleTestCommand::class, ['--name' => BarJobStub::class])
            ->assertSuccessful()
            ->expectsOutputToContain(sprintf('Running [%s]', BarJobStub::class));

        $this->artisan(ScheduleTestCommand::class, ['--name' => 'callback'])
            ->assertSuccessful()
            ->expectsOutputToContain('Running [callback]');
    }

    public function testRunUsingChoices()
    {
        $this->schedule->command(BarCommandStub::class)->name('bar-command');
        $this->schedule->job(BarJobStub::class);
        $this->schedule->call(fn () => true)->name('callback');

        $this->artisan(ScheduleTestCommand::class)
            ->assertSuccessful()
            ->expectsChoice(
                'Which command would you like to run?',
                'callback',
                ['php artisan bar:command', BarJobStub::class, 'callback'],
                true
            )
            ->expectsOutputToContain('Running [callback]');
    }
}

class BarCommandStub extends Command
{
    protected ?string $signature = 'bar:command';

    protected string $description = 'This is the description of the command.';

    public function handle()
    {
    }
}

class BarJobStub
{
    public function __invoke()
    {
    }
}
