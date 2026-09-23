<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Support\ServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:provider')]
class ProviderMakeCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:provider
                    {name : The name of the provider}
                    {--f|force : Create the class even if the provider already exists}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new service provider class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Provider';

    /**
     * Execute the console command.
     *
     * @throws FileNotFoundException
     */
    public function handle(): bool|int
    {
        $result = parent::handle();

        if ($result === false) {
            return $result;
        }

        ServiceProvider::addProviderToBootstrapFile(
            $this->qualifyClass($this->getNameInput()),
            $this->hypervel->getBootstrapProvidersPath(), // @phpstan-ignore method.notFound
        );

        return $result;
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/provider.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->hypervel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__ . $stub;
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Providers';
    }
}
