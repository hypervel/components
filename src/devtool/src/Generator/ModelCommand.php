<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:model')]
class ModelCommand extends DevtoolGeneratorCommand
{
    protected ?string $name = 'make:model';

    protected string $description = 'Create a new Eloquent model class';

    protected string $type = 'Model';

    /**
     * Execute the console command.
     */
    public function handle(): bool|int
    {
        if (parent::handle() === self::FAILURE) {
            return self::FAILURE;
        }

        if ($this->option('all')) {
            $this->input->setOption('factory', true);
            $this->input->setOption('seed', true);
            $this->input->setOption('migration', true);
            $this->input->setOption('controller', true);
            $this->input->setOption('policy', true);
            $this->input->setOption('resource', true);
        }

        if ($this->option('factory')) {
            $this->createFactory();
        }

        if ($this->option('migration')) {
            $this->createMigration();
        }

        if ($this->option('seed')) {
            $this->createSeeder();
        }

        if ($this->option('controller') || $this->option('resource') || $this->option('api')) {
            $this->createController();
        } elseif ($this->option('requests')) {
            $this->createFormRequests();
        }

        if ($this->option('policy')) {
            $this->createPolicy();
        }

        return self::SUCCESS;
    }

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $stub = parent::replaceClass($stub, $name);

        $uses = $this->getConfig()['uses'] ?? \Hypervel\Database\Eloquent\Model::class;

        return str_replace('%USES%', $uses, $stub);
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/model.stub';
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Models';
    }

    protected function getOptions(): array
    {
        return [
            ['namespace', 'N', InputOption::VALUE_OPTIONAL, 'The namespace for class.', null],
            ['all', 'a', InputOption::VALUE_NONE, 'Generate a migration, seeder, factory and policy classes for the model'],
            ['factory', 'f', InputOption::VALUE_NONE, 'Create a new factory for the model'],
            ['force', null, InputOption::VALUE_NONE, 'Create the class even if the model already exists'],
            ['migration', 'm', InputOption::VALUE_NONE, 'Create a new migration file for the model'],
            ['seed', 's', InputOption::VALUE_NONE, 'Create a new seeder for the model'],
            ['controller', 'c', InputOption::VALUE_NONE, 'Create a new controller for the model'],
            ['policy', null, InputOption::VALUE_NONE, 'Create a new policy for the model'],
            ['resource', 'r', InputOption::VALUE_NONE, 'Indicates if the generated controller should be a resource controller'],
            ['api', null, InputOption::VALUE_NONE, 'Indicates if the generated controller should be an API resource controller'],
            ['requests', 'R', InputOption::VALUE_NONE, 'Create new form request classes and use them in the resource controller'],
        ];
    }

    /**
     * Create a model factory for the model.
     */
    protected function createFactory(): void
    {
        $factory = Str::studly($this->argument('name'));

        $this->call('make:factory', [
            'name' => "{$factory}Factory",
            '--force' => $this->option('force'),
        ]);
    }

    /**
     * Create a migration file for the model.
     */
    protected function createMigration(): void
    {
        $table = Str::snake(Str::pluralStudly(class_basename($this->argument('name'))));

        $this->call('make:migration', [
            'name' => "create_{$table}_table",
            '--create' => $table,
        ]);
    }

    /**
     * Create a seeder file for the model.
     */
    protected function createSeeder(): void
    {
        $seeder = Str::studly($this->argument('name'));

        $this->call('make:seeder', [
            'name' => "{$seeder}Seeder",
            '--force' => $this->option('force'),
        ]);
    }

    /**
     * Create a controller for the model.
     */
    protected function createController(): void
    {
        $controller = Str::studly($this->argument('name'));

        $modelName = $this->qualifyClass($this->getNameInput());

        $this->call('make:controller', array_filter([
            'name' => "{$controller}Controller",
            '--model' => $this->option('resource') || $this->option('api') ? $modelName : null,
            '--api' => $this->option('api'),
            '--requests' => $this->option('requests') || $this->option('all'),
        ]));
    }

    /**
     * Create the form requests for the model.
     */
    protected function createFormRequests(): void
    {
        $request = Str::studly($this->argument('name'));

        $this->call('make:request', [
            'name' => "Store{$request}Request",
        ]);

        $this->call('make:request', [
            'name' => "Update{$request}Request",
        ]);
    }

    /**
     * Create a policy file for the model.
     */
    protected function createPolicy(): void
    {
        $policy = Str::studly($this->argument('name'));

        $this->call('make:policy', [
            'name' => "{$policy}Policy",
            '--model' => $policy,
        ]);
    }
}
