<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Console\Commands;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'make:mcp-server',
    description: 'Create a new MCP server class'
)]
class MakeServerCommand extends GeneratorCommand
{
    protected string $type = 'Server';

    /**
     * Get the server stub.
     */
    protected function getStub(): string
    {
        return file_exists($customPath = $this->getHypervel()->basePath('stubs/mcp-server.stub'))
            ? $customPath
            : __DIR__ . '/../../../stubs/mcp-server.stub';
    }

    /**
     * Get the default server namespace.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return "{$rootNamespace}\\Mcp\\Servers";
    }

    /**
     * Get the generation options.
     *
     * @return array<int, array<int, int|string>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the server already exists'],
        ];
    }

    /**
     * Build the server class with its display name.
     *
     * @throws FileNotFoundException
     */
    protected function buildClass(string $name): string
    {
        $stub = parent::buildClass($name);

        $className = class_basename($name);

        $serverDisplayName = trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $className));

        return str_replace('{{ serverDisplayName }}', $serverDisplayName, $stub);
    }
}
