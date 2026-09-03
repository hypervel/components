<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute as AfterExecuteCommand;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Symfony\Component\Console\Input\InputInterface;

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

        $input = $this->redactInput($command, $event->input);

        Telescope::recordCommand(IncomingEntry::make([
            'command' => $command->getName(),
            'exit_code' => $event->exitCode,
            'arguments' => $input['arguments'],
            'options' => $input['options'],
        ]));
    }

    /**
     * Redact value-bearing command input for storage.
     *
     * @return array{arguments: array<string, mixed>, options: array<string, mixed>}
     */
    private function redactInput(Command $command, InputInterface $input): array
    {
        $arguments = array_map(
            static fn (mixed $value): mixed => $value === null ? null : Telescope::REDACTED_VALUE,
            $input->getArguments(),
        );

        $definition = $command->getDefinition();
        $options = [];

        foreach ($input->getOptions() as $name => $value) {
            // An unknown option cannot be classified safely, and observation must not fail the command.
            $acceptsValue = ! $definition->hasOption($name)
                || $definition->getOption($name)->acceptValue();

            $options[$name] = $acceptsValue && $value !== null
                ? Telescope::REDACTED_VALUE
                : $value;
        }

        return ['arguments' => $arguments, 'options' => $options];
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
