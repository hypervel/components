<?php

declare(strict_types=1);

namespace Hypervel\Data\Console;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:data')]
class DataMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:data';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new data class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Data';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/data.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->hypervel->basePath(trim($stub, '/')))
            ? $customPath
            : dirname(__DIR__, 2) . $stub;
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        // Hypervel's generators already provide --target-namespace for a one-off namespace.
        $namespace = trim(config()->string('data.commands.make.namespace'), '\\');

        return trim($rootNamespace . '\\' . $namespace, '\\');
    }

    /**
     * Parse the class name and format according to the root namespace.
     */
    protected function qualifyClass(string $name): string
    {
        $suffix = trim($this->option('suffix'));

        if ($suffix !== '' && ! Str::endsWith($name, $suffix)) {
            $name .= $suffix;
        }

        return parent::qualifyClass($name);
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            [
                'suffix',
                's',
                InputOption::VALUE_REQUIRED,
                'Suffix the class with this value.',
                config()->string('data.commands.make.suffix'),
            ],
            [
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Create the Data class even if the file already exists.',
            ],
        ];
    }
}
