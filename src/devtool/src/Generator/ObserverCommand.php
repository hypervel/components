<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:observer')]
class ObserverCommand extends GeneratorCommand
{
    protected ?string $name = 'make:observer';

    protected string $description = 'Create a new model observer class';

    protected string $type = 'Observer';

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $stub = parent::replaceClass($stub, $name);
        if (! $model = trim($this->option('model') ?? '')) {
            $modelParts = explode('\\', $name);
            $model = end($modelParts);
            $model = Str::ucfirst(Str::before($model, 'Observer'));
        }

        $modelNamespace = $this->getConfig()['model_namespace'] ?? 'App\Models';
        $modelNamespace = "{$modelNamespace}\\{$model}";
        $modelVariable = Str::camel($model);

        return str_replace(
            ['%NAMESPACE_MODEL%', '%MODEL%', '%MODEL_VARIABLE%'],
            [$modelNamespace, $model, $modelVariable],
            $stub
        );
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/observer.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Observers';
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The model that the observer applies to'],
        ]);
    }
}
