<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:controller')]
class ControllerCommand extends GeneratorCommand
{
    protected ?string $name = 'make:controller';

    protected string $description = 'Create a new controller class';

    protected string $type = 'Controller';

    protected function getStub(): string
    {
        $stub = null;

        if ($type = $this->option('type')) {
            $stub = "/stubs/controller.{$type}.stub";
        } elseif ($this->option('model')) {
            $stub = '/stubs/controller.model.stub';
        } elseif ($this->option('resource')) {
            $stub = '/stubs/controller.stub';
        }
        if ($this->option('api') && is_null($stub)) {
            $stub = '/stubs/controller.api.stub';
        } elseif ($this->option('api')) {
            $stub = str_replace('.stub', '.api.stub', $stub);
        }

        $stub ??= '/stubs/controller.plain.stub';

        return $this->getConfig()['stub'] ?? __DIR__ . $stub;
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Http\Controllers';
    }

    protected function getOptions(): array
    {
        return [
            ['namespace', 'N', InputOption::VALUE_OPTIONAL, 'The namespace for class.', null],
            ['api', null, InputOption::VALUE_NONE, 'Exclude the create and edit methods from the controller'],
            ['type', null, InputOption::VALUE_REQUIRED, 'Manually specify the controller stub file to use'],
            ['force', null, InputOption::VALUE_NONE, 'Create the class even if the controller already exists'],
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'Generate a resource controller for the given model'],
            ['resource', 'r', InputOption::VALUE_NONE, 'Generate a resource controller class'],
            ['requests', 'R', InputOption::VALUE_NONE, 'Generate FormRequest classes for store and update'],
        ];
    }

    protected function replaceClass(string $stub, string $name): string
    {
        $stub = parent::replaceClass($stub, $name);
        if (! $model = $this->option('model')) {
            return $stub;
        }
        $modelNamespace = $this->qualifyModel($model);
        $model = class_basename($modelNamespace);
        $modelVariable = Str::camel($model);

        [$namespace, $storeRequest, $updateRequest] = [
            'Hypervel\Http', 'Request', 'Request',
        ];

        if ($this->option('requests')) {
            $namespace = 'App\Http\Requests';

            [$storeRequest, $updateRequest] = $this->generateFormRequests($model);
        }
        $namespacedRequests = $namespace . '\\' . $storeRequest . ';';

        if ($storeRequest !== $updateRequest) {
            $namespacedRequests .= PHP_EOL . 'use ' . $namespace . '\\' . $updateRequest . ';';
        }
        return str_replace(
            ['%NAMESPACED_MODEL%', '%MODEL%', '%MODEL_VARIABLE%', '%NAMESPACED_REQUESTS%', '%STORE_REQUEST%', '%UPDATE_REQUEST%'],
            [$modelNamespace, $model, $modelVariable, $namespacedRequests, $storeRequest, $updateRequest],
            $stub
        );
    }

    protected function generateFormRequests(string $modelClass): array
    {
        $storeRequestClass = 'Store' . $modelClass . 'Request';

        $this->call('make:request', [
            'name' => $storeRequestClass,
        ]);

        $updateRequestClass = 'Update' . $modelClass . 'Request';

        $this->call('make:request', [
            'name' => $updateRequestClass,
        ]);

        return [$storeRequestClass, $updateRequestClass];
    }

    protected function qualifyModel(string $model): string
    {
        $model = ltrim($model, '\/');

        $model = str_replace('/', '\\', $model);
        $modelNamespace = $this->getConfig()['model_namespace'] ?? 'App\Models';

        if (Str::startsWith($model, $modelNamespace)) {
            return $model;
        }

        return "{$modelNamespace}\\{$model}";
    }
}
