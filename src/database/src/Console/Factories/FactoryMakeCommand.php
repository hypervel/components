<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Factories;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:factory')]
class FactoryMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:factory';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new model factory';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Factory';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/factory.stub');
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
     * Build the class with the given name.
     */
    protected function buildClass(string $name): string
    {
        $factory = class_basename(Str::ucfirst(str_replace('Factory', '', $name)));

        $namespaceModel = $this->option('model')
            ? $this->qualifyModel($this->option('model'))
            : $this->qualifyModel($this->guessModelName($name));

        $model = class_basename($namespaceModel);

        $namespace = $this->getNamespace(
            Str::replaceFirst($this->rootNamespace(), 'Database\Factories\\', $this->qualifyClass($this->getNameInput()))
        );

        $replace = [
            '{{ factoryNamespace }}' => $namespace,
            'NamespacedDummyModel' => $namespaceModel,
            '{{ namespacedModel }}' => $namespaceModel,
            '{{namespacedModel}}' => $namespaceModel,
            'DummyModel' => $model,
            '{{ model }}' => $model,
            '{{model}}' => $model,
            '{{ factory }}' => $factory,
            '{{factory}}' => $factory,
        ];

        return str_replace(
            array_keys($replace),
            array_values($replace),
            parent::buildClass($name)
        );
    }

    /**
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        $name = (new Stringable($name))->replaceFirst($this->rootNamespace(), '')->finish('Factory')->value();

        return $this->hypervel->databasePath() . '/factories/' . str_replace('\\', '/', $name) . '.php';
    }

    /**
     * Guess the model name from the Factory name or return a default model name.
     */
    protected function guessModelName(string $name): string
    {
        if (str_ends_with($name, 'Factory')) {
            $name = substr($name, 0, -7);
        }

        $modelName = $this->qualifyModel(Str::after($name, $this->rootNamespace()));

        if (class_exists($modelName)) {
            return $modelName;
        }

        if (is_dir(app_path('Models/'))) {
            return $this->rootNamespace() . 'Models\Model';
        }

        return $this->rootNamespace() . 'Model';
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The name of the model'],
        ];
    }
}
