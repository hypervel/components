<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Actions;

use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Command;
use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Testbench\Console\Task;
use RuntimeException;

class RunCommand extends Action
{
    /**
     * Construct a new action instance.
     */
    public function __construct(
        public Command|ConsoleApplication $console,
        public ?ComponentsFactory $components = null,
        bool $pretending = false,
    ) {
        $this->pretending = $pretending;
    }

    /**
     * Handle the action.
     *
     * @param array<string, mixed> $parameters
     */
    public function handle(string $name, array $parameters = []): void
    {
        Task::action(fn (): bool => $this->console->call($name, $parameters) === Command::SUCCESS)
            ->response(function (bool $successful, bool $pretending) use ($name): void {
                if ($pretending === true) {
                    $this->components?->task(sprintf('Command [%s] would be executed', $name));

                    return;
                }

                // Surface a failed exit status so the calling command can report its own failure.
                if (! $successful) {
                    throw new RuntimeException("Unable to run command [{$name}].");
                }
            })->dispatch($this->pretending);
    }
}
