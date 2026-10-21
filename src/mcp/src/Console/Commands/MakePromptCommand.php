<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Console\Commands;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'make:mcp-prompt',
    description: 'Create a new MCP prompt class'
)]
class MakePromptCommand extends GeneratorCommand
{
    protected string $type = 'Prompt';

    /**
     * Get the prompt stub.
     */
    protected function getStub(): string
    {
        return file_exists($customPath = $this->getHypervel()->basePath('stubs/mcp-prompt.stub'))
            ? $customPath
            : __DIR__ . '/../../../stubs/mcp-prompt.stub';
    }

    /**
     * Get the default prompt namespace.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return "{$rootNamespace}\\Mcp\\Prompts";
    }

    /**
     * Get the generation options.
     *
     * @return array<int, array<int, int|string>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the prompt already exists'],
        ];
    }
}
