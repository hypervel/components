<?php

declare(strict_types=1);

namespace Hypervel\Ai\Console\Commands;

use Hypervel\Console\Attributes\Description;
use Hypervel\Console\GeneratorCommand;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:tool')]
#[Description('Create a new agent tool')]
class MakeToolCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:tool';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Tool';

    /**
     * Get the default namespace for the class.
     */
    #[Override]
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Ai\Tools';
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/tool.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->hypervel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__ . '/../../../' . $stub;
    }

    /**
     * Get the console command arguments.
     */
    #[Override]
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the tool even if the tool already exists'],
        ];
    }
}
