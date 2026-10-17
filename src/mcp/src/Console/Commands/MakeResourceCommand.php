<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Console\Commands;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'make:mcp-resource',
    description: 'Create a new MCP resource class'
)]
class MakeResourceCommand extends GeneratorCommand
{
    protected string $type = 'Resource';

    /**
     * Get the resource stub.
     */
    protected function getStub(): string
    {
        return file_exists($customPath = $this->getHypervel()->basePath('stubs/mcp-resource.stub'))
            ? $customPath
            : __DIR__ . '/../../../stubs/mcp-resource.stub';
    }

    /**
     * Get the default resource namespace.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return "{$rootNamespace}\\Mcp\\Resources";
    }

    /**
     * Get the generation options.
     *
     * @return array<int, array<int, int|string>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the resource already exists'],
        ];
    }
}
