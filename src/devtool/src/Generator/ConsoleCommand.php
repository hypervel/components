<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:command')]
class ConsoleCommand extends GeneratorCommand
{
    protected ?string $name = 'make:command';

    protected string $description = 'Create a new Artisan command';

    protected string $type = 'Console command';

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $stub = parent::replaceClass($stub, $name);
        $command = $this->option('command') ?: 'app:' . Str::of($name)->classBasename()->kebab()->value();

        return str_replace('%COMMAND%', $command, $stub);
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/console.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Console\Commands';
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['command', null, InputOption::VALUE_OPTIONAL, 'The terminal command that will be used to invoke the class.'],
        ]);
    }
}
