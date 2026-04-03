<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:channel')]
class ChannelMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:channel';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new channel class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Channel';

    /**
     * Build the class with the given name.
     */
    protected function buildClass(string $name): string
    {
        return str_replace(
            ['DummyUser', '{{ userModel }}'],
            class_basename($this->userProviderModel()),
            parent::buildClass($name)
        );
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return __DIR__ . '/stubs/channel.stub';
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Broadcasting';
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the channel already exists'],
        ];
    }
}
