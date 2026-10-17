<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Console\Commands;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'make:mcp-tool',
    description: 'Create a new MCP tool class'
)]
class MakeToolCommand extends GeneratorCommand
{
    protected string $type = 'Tool';

    /**
     * Get the tool stub.
     */
    protected function getStub(): string
    {
        return file_exists($customPath = $this->getHypervel()->basePath('stubs/mcp-tool.stub'))
            ? $customPath
            : __DIR__ . '/../../../stubs/mcp-tool.stub';
    }

    /**
     * Get the default tool namespace.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return "{$rootNamespace}\\Mcp\\Tools";
    }

    /**
     * Get the generation options.
     *
     * @return array<int, array<int, int|string>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the tool already exists'],
        ];
    }

    /**
     * Build the tool class with its title.
     *
     * @throws FileNotFoundException
     */
    protected function buildClass(string $name): string
    {
        $stub = parent::buildClass($name);

        $className = class_basename($name);
        $title = Str::headline($className);

        return str_replace(
            '{{ title }}',
            $title,
            $stub,
        );
    }
}
