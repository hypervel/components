<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class FactoryCommand extends GeneratorCommand
{
    protected ?string $name = 'make:factory';

    protected string $description = 'Create a new model factory';

    protected string $type = 'Factory';

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/factory.stub';
    }

    protected function getModelNamespace(string $name): string
    {
        $namespace = $this->getConfig()['model_namespace'] ?? 'App\Models';

        return "{$namespace}\\{$name}";
    }

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $factory = Str::ucfirst(str_replace('Factory', '', $name));

        $model = $this->option('model') ?: $factory;

        $replace = [
            '%MODEL%' => $model,
            '%MODEL_NAMESPACE%' => $this->getModelNamespace($model),
        ];

        return str_replace(
            array_keys($replace),
            array_values($replace),
            $stub
        );
    }

    /**
     * Parse the class name and format according to the root namespace.
     */
    protected function qualifyClass(string $name): string
    {
        return $name;
    }

    /**
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        $path = $this->getConfig()['path'] ?? 'database/factories';

        return BASE_PATH . "/{$path}/{$name}.php";
    }

    protected function getDefaultNamespace(): string
    {
        return '';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Whether force to rewrite.'],
            ['namespace', 'N', InputOption::VALUE_OPTIONAL, 'The namespace for class.', null],
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The name of the model'],
        ];
    }
}
