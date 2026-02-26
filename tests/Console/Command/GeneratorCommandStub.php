<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Command;

use Hypervel\Devtool\Generator\DevtoolGeneratorCommand;
use Symfony\Component\Console\Input\InputInterface;

class GeneratorCommandStub extends DevtoolGeneratorCommand
{
    protected ?string $name = 'gen:test-stub';

    protected string $description = 'Test stub command';

    /**
     * Set the input instance for testing.
     */
    public function setTestInput(InputInterface $input): void
    {
        $this->input = $input;
    }

    /**
     * Expose getPath() for testing.
     */
    public function exposedGetPath(string $name): string
    {
        return $this->getPath($name);
    }

    /**
     * Expose qualifyClass() for testing.
     */
    public function exposedQualifyClass(string $name): string
    {
        return $this->qualifyClass($name);
    }

    /**
     * Expose isReservedName() for testing.
     */
    public function exposedIsReservedName(string $name): bool
    {
        return $this->isReservedName($name);
    }

    /**
     * Expose sortImports() for testing.
     */
    public function exposedSortImports(string $stub): string
    {
        return $this->sortImports($stub);
    }

    /**
     * Expose rootNamespace() for testing.
     */
    public function exposedRootNamespace(): string
    {
        return $this->rootNamespace();
    }

    protected function rootNamespace(): string
    {
        return 'App\\';
    }

    protected function getStub(): string
    {
        return __DIR__ . '/stubs/class.stub';
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return 'App';
    }
}
