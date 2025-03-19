<?php

declare(strict_types=1);

namespace Hypervel\Scheduling\Console;

use FriendsOfHyperf\PrettyConsole\Traits\Prettyable;
use Hyperf\Command\Command;
use Hypervel\Scheduling\CallbackEvent;
use Hypervel\Scheduling\Schedule;
use Hypervel\Support\Traits\HasLaravelStyleCommand;

use function Hypervel\Prompts\select;

class ScheduleTestCommand extends Command
{
    use HasLaravelStyleCommand;
    use Prettyable;

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
        $commands = $this->app->get(Schedule::class)->events();

        $commandNames = [];

        foreach ($commands as $command) {
            $commandNames[] = $command->command ?? $command->getSummaryForDisplay();
        }

        if (empty($commandNames)) {
            return $this->info('No scheduled commands have been defined.');
        }

        if (! empty($name = $this->option('name'))) {
            $matches = array_filter($commandNames, function ($commandName) use ($name) {
                return trim($commandName) === $name;
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
            : $event->command;

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
