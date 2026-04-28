<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Commands;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'inertia:middleware')]
class CreateMiddleware extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'inertia:middleware';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new Inertia middleware';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Middleware';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/middleware.stub';
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Http\Middleware';
    }

    /**
     * Get the console command arguments.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function getArguments(): array
    {
        return [
            ['name', InputOption::VALUE_REQUIRED, 'Name of the Middleware that should be created', 'HandleInertiaRequests'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', null, InputOption::VALUE_NONE, 'Create the class even if the Middleware already exists'],
        ];
    }
}
