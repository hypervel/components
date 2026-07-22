<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Commands;

use Hypervel\Console\Concerns\CreatesMatchingTest;
use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputInterface;

class GeneratorCommandStub extends GeneratorCommand
{
    use CreatesMatchingTest;

    public bool $matchingTestCreationHandled = false;

    protected ?string $name = 'make:test-stub';

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

    /**
     * Expose userProviderModel() for testing.
     */
    public function exposedUserProviderModel(): ?string
    {
        return $this->userProviderModel();
    }

    /**
     * Expose replaceFile() for testing.
     */
    public function exposedReplaceFile(string $path, string $contents): void
    {
        $this->replaceFile($path, $contents);
    }

    protected function rootNamespace(): string
    {
        return 'App\\';
    }

    protected function getStub(): string
    {
        return __DIR__ . '/../Fixtures/class.stub';
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return 'App';
    }

    /**
     * Record matching test creation.
     */
    protected function handleTestCreation(string $path): bool
    {
        $this->matchingTestCreationHandled = true;

        return true;
    }
}
