<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class ConsoleCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:command');
    }

    public function configure()
    {
        $this->setDescription('Create a new Artisan command');

        parent::configure();
    }

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $stub = parent::replaceClass($stub, $name);
        $command = $this->input->getOption('command') ?: 'app:' . Str::of($name)->classBasename()->kebab()->value();

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
