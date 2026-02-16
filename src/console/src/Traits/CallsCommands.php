<?php

declare(strict_types=1);

namespace Hypervel\Console\Traits;

use Hypervel\Support\Collection;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

trait CallsCommands
{
    /**
     * Resolve the console command instance for the given command.
     */
    abstract protected function resolveCommand(SymfonyCommand|string $command): SymfonyCommand;

    /**
     * Call another console command.
     */
    public function call(SymfonyCommand|string $command, array $arguments = []): int
    {
        return $this->runCommand($command, $arguments, $this->output);
    }

    /**
     * Call another console command without output.
     */
    public function callSilent(SymfonyCommand|string $command, array $arguments = []): int
    {
        return $this->runCommand($command, $arguments, new NullOutput());
    }

    /**
     * Call another console command without output.
     */
    public function callSilently(SymfonyCommand|string $command, array $arguments = []): int
    {
        return $this->callSilent($command, $arguments);
    }

    /**
     * Run the given console command.
     */
    protected function runCommand(SymfonyCommand|string $command, array $arguments, OutputInterface $output): int
    {
        $arguments['command'] = $command;

        $result = $this->resolveCommand($command)->run(
            $this->createInputFromArguments($arguments), $output
        );

        $this->restorePrompts();

        return $result;
    }

    /**
     * Create an input instance from the given arguments.
     */
    protected function createInputFromArguments(array $arguments): ArrayInput
    {
        return tap(new ArrayInput(array_merge($this->context(), $arguments)), function (ArrayInput $input) {
            if ($input->getParameterOption('--no-interaction')) {
                $input->setInteractive(false);
            }
        });
    }

    /**
     * Get all the context passed to the command.
     *
     * @return array{'--ansi'?: bool, '--no-ansi'?: bool, '--no-interaction'?: bool, '--quiet'?: bool, '--verbose'?: bool}
     */
    protected function context(): array
    {
        return (new Collection($this->option()))
            ->only([
                'ansi',
                'no-ansi',
                'no-interaction',
                'quiet',
                'verbose',
            ])
            ->filter()
            ->mapWithKeys(fn ($value, $key) => ["--{$key}" => $value])
            ->all();
    }
}
