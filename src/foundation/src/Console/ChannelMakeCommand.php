<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:channel')]
class ChannelMakeCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:channel
                    {name : The name of the channel}
                    {--f|force : Create the class even if the channel already exists}';

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
}
