<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:rule')]
class RuleMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:rule';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new validation rule';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Rule';

    /**
     * Build the class with the given name.
     *
     * @throws \Hypervel\Contracts\Filesystem\FileNotFoundException
     */
    protected function buildClass(string $name): string
    {
        return str_replace(
            '{{ ruleType }}',
            $this->option('implicit') ? 'ImplicitRule' : 'Rule',
            parent::buildClass($name)
        );
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        $stub = $this->option('implicit')
            ? '/stubs/rule.implicit.stub'
            : '/stubs/rule.stub';

        return file_exists($customPath = $this->hypervel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__ . $stub;
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Rules';
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the rule already exists'],
            ['implicit', 'i', InputOption::VALUE_NONE, 'Generate an implicit rule'],
        ];
    }
}
