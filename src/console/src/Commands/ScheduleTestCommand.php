<?php

declare(strict_types=1);

namespace Hypervel\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\Schedule;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Prompts\select;

#[AsCommand(name: 'schedule:test')]
class ScheduleTestCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'schedule:test {--name= : The name of the scheduled command to run}';

    /**
     * The console command description.
     */
    protected string $description = 'Run a scheduled command';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $commands = $this->app->make(Schedule::class)->events();

        $commandNames = [];

        foreach ($commands as $command) {
            $commandNames[] = $command->command ?? $command->getSummaryForDisplay();
        }

        if (empty($commandNames)) {
            return $this->info('No scheduled commands have been defined.');
        }

        if (! empty($name = $this->option('name'))) {
            $matches = array_filter($commandNames, function ($commandName) use ($name) {
                return trim(Event::normalizeCommand($commandName)) === $name;
            });

            if (count($matches) !== 1) {
                $this->components->info('No matching scheduled command found.');

                return;
            }

            $index = key($matches);
        } else {
            $index = $this->getSelectedCommandByIndex($commandNames);
        }

        $event = $commands[$index];

        $summary = $event->getSummaryForDisplay();

        $command = $event instanceof CallbackEvent
            ? $summary
            : Event::normalizeCommand($event->command);

        $description = sprintf(
            'Running [%s]%s',
            $command,
            $event->runInBackground ? ' normally in background' : '',
        );

        $event->runInBackground = false;

        $this->components->task($description, fn () => $event->run($this->app));

        if (! $event instanceof CallbackEvent) {
            $this->components->bulletList([$event->getSummaryForDisplay()]);
        }

        $this->newLine();
    }

    /**
     * Get the selected command name by index.
     */
    protected function getSelectedCommandByIndex(array $commandNames): int
    {
        if (count($commandNames) !== count(array_unique($commandNames))) {
            // Some commands (likely closures) have the same name, append unique indexes to each one...
            $uniqueCommandNames = array_map(function ($index, $value) {
                return "{$value} [{$index}]";
            }, array_keys($commandNames), $commandNames);

            $selectedCommand = select('Which command would you like to run?', $uniqueCommandNames);

            preg_match('/\[(\d+)\]/', $selectedCommand, $choice);

            return (int) $choice[1];
        }
        return array_search(
            select('Which command would you like to run?', $commandNames),
            $commandNames
        );
    }
}
