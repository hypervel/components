<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Command\Command;
use Hyperf\Command\Event\AfterExecute as AfterExecuteCommand;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class CommandWatcher extends Watcher
{
    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        $app->get(EventDispatcherInterface::class)
            ->listen(AfterExecuteCommand::class, [$this, 'recordCommand']);
    }

    /**
     * Record an Artisan command was executed.
     */
    public function recordCommand(AfterExecuteCommand $event): void
    {
        $command = $event->getCommand();
        if (! Telescope::isRecording() || $this->shouldIgnore($command)) {
            return;
        }

        Telescope::recordCommand(IncomingEntry::make([
            'command' => $command->getName(),
            'exit_code' => (fn () => $this->exitCode)->call($command), /* @phpstan-ignore-line */
            'arguments' => (fn () => $this->input->getArguments())->call($command), /* @phpstan-ignore-line */
            'options' => (fn () => $this->input->getOptions())->call($command), /* @phpstan-ignore-line */
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
                'crontab:run',
            ])
        );
    }
}
