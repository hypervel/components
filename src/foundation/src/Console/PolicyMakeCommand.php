<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\suggest;

#[AsCommand(name: 'make:policy')]
class PolicyMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:policy';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new policy class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Policy';

    /**
     * Build the class with the given name.
     */
    protected function buildClass(string $name): string
    {
        $stub = $this->replaceUserNamespace(
            parent::buildClass($name)
        );

        $model = $this->option('model');

        return $model ? $this->replaceModel($stub, $model) : $stub;
    }

    /**
     * Replace the User model namespace.
     */
    protected function replaceUserNamespace(string $stub): string
    {
        $model = $this->userProviderModel();

        if (! $model) {
            return $stub;
        }

        return str_replace(
            $this->rootNamespace() . 'User',
            $model,
            $stub
        );
    }

    /**
     * Get the model for the guard's user provider.
     *
     * @throws LogicException
     */
    protected function userProviderModel(): ?string
    {
        $config = $this->hypervel['config'];

        $guard = $this->option('guard') ?: $config->get('auth.defaults.guard');

        if (is_null($guardProvider = $config->get('auth.guards.' . $guard . '.provider'))) {
            throw new LogicException('The [' . $guard . '] guard is not defined in your "auth" configuration file.');
        }

        if (! $config->get('auth.providers.' . $guardProvider . '.model')) {
            return 'App\Models\User';
        }

        return $config->get(
            'auth.providers.' . $guardProvider . '.model'
        );
    }

    /**
     * Replace the model for the given stub.
     */
    protected function replaceModel(string $stub, string $model): string
    {
        $model = str_replace('/', '\\', $model);

        if (str_starts_with($model, '\\')) {
            $namespacedModel = trim($model, '\\');
        } else {
            $namespacedModel = $this->qualifyModel($model);
        }

        $model = class_basename(trim($model, '\\'));

        $dummyUser = class_basename($this->userProviderModel());

        $dummyModel = Str::camel($model) === 'user' ? 'model' : $model;

        $replace = [
            'NamespacedDummyModel' => $namespacedModel,
            '{{ namespacedModel }}' => $namespacedModel,
            '{{namespacedModel}}' => $namespacedModel,
            'DummyModel' => $model,
            '{{ model }}' => $model,
            '{{model}}' => $model,
            'dummyModel' => Str::camel($dummyModel),
            '{{ modelVariable }}' => Str::camel($dummyModel),
            '{{modelVariable}}' => Str::camel($dummyModel),
            'DummyUser' => $dummyUser,
            '{{ user }}' => $dummyUser,
            '{{user}}' => $dummyUser,
            '$user' => '$' . Str::camel($dummyUser),
        ];

        $stub = str_replace(
            array_keys($replace),
            array_values($replace),
            $stub
        );

        return preg_replace(
            vsprintf('/use %s;[\r\n]+use %s;/', [
                preg_quote($namespacedModel, '/'),
                preg_quote($namespacedModel, '/'),
            ]),
            "use {$namespacedModel};",
            $stub
        );
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->option('model')
            ? $this->resolveStubPath('/stubs/policy.stub')
            : $this->resolveStubPath('/stubs/policy.plain.stub');
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
        return $rootNamespace . '\Policies';
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the policy already exists'],
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The model that the policy applies to'],
            ['guard', 'g', InputOption::VALUE_OPTIONAL, 'The guard that the policy relies on'],
        ];
    }

    /**
     * Interact further with the user if they were prompted for missing arguments.
     */
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        if ($this->isReservedName($this->getNameInput()) || $this->didReceiveOptions($input)) {
            return;
        }

        $model = suggest(
            'What model should this policy apply to? (Optional)',
            $this->findAvailableModels(),
        );

        if ($model) {
            $input->setOption('model', $model);
        }
    }
}
