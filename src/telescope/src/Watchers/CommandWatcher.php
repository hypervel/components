<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute as AfterExecuteCommand;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;

class CommandWatcher extends Watcher
{
    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
        $app->make(Dispatcher::class)
            ->listen(AfterExecuteCommand::class, [$this, 'recordCommand']);
    }

    /**
     * Record an Artisan command was executed.
     */
    public function recordCommand(AfterExecuteCommand $event): void
    {
        $command = $event->command;
        if (! Telescope::isRecording() || $this->shouldIgnore($command)) {
            return;
        }

        Telescope::recordCommand(IncomingEntry::make([
            'command' => $command->getName(),
            'exit_code' => $event->exitCode,
            'arguments' => $event->input?->getArguments() ?? [],
            'options' => $event->input?->getOptions() ?? [],
        ]));
    }

    /**
     * Determine if the event should be ignored.
     */
    private function shouldIgnore(Command $command): bool
    {
        return in_array(
            $command->getName(),
            array_merge($this->options['ignore'] ?? [], [
                'schedule:run',
                'package:discover',
            ]),
            true
        );
    }
}
