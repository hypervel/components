<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Commands;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'pennant:feature')]
class FeatureMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'pennant:feature';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new feature class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Feature';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return file_exists($customPath = $this->hypervel->basePath('stubs/feature.stub'))
            ? $customPath
            : __DIR__ . '/../../stubs/feature.stub';
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return "{$rootNamespace}\\Features";
    }

    /**
     * Get the console command arguments.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the feature already exists'],
        ];
    }
}
