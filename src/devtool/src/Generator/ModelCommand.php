<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;
use Hyperf\Stringable\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ModelCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:model');
    }

    public function configure()
    {
        $this->setDescription('Create a new Eloquent model class');

        parent::configure();
    }

    /**
     * Execute the console command.
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        if ($this->input->getOption('all')) {
            $this->input->setOption('factory', true);
            $this->input->setOption('seed', true);
            $this->input->setOption('migration', true);
            $this->input->setOption('controller', true);
            $this->input->setOption('policy', true);
            $this->input->setOption('resource', true);
        }

        if ($this->input->getOption('factory')) {
            $this->createFactory();
        }

        if ($this->input->getOption('migration')) {
            $this->createMigration();
        }

        if ($this->input->getOption('seed')) {
            $this->createSeeder();
        }

        if ($this->input->getOption('controller') || $this->input->getOption('resource') || $this->input->getOption('api')) {
            $this->createController();
        } elseif ($this->input->getOption('requests')) {
            $this->createFormRequests();
        }

        if ($this->input->getOption('policy')) {
            $this->createPolicy();
        }
        return 0;
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

    protected function getDefaultNamespace(): string
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
    protected function createFactory()
    {
        $factory = Str::studly($this->input->getArgument('name'));

        $this->call('make:factory', [
            'name' => "{$factory}Factory",
            '--force' => $this->input->getOption('force'),
        ]);
    }

    /**
     * Create a migration file for the model.
     */
    protected function createMigration()
    {
        $table = Str::snake(Str::pluralStudly(class_basename($this->input->getArgument('name'))));

        $this->call('make:migration', [
            'name' => "create_{$table}_table",
            '--create' => $table,
        ]);
    }

    /**
     * Create a seeder file for the model.
     */
    protected function createSeeder()
    {
        $seeder = Str::studly($this->input->getArgument('name'));

        $this->call('make:seeder', [
            'name' => "{$seeder}Seeder",
            '--force' => $this->input->getOption('force'),
        ]);
    }

    /**
     * Create a controller for the model.
     *
     * @return void
     */
    protected function createController()
    {
        $controller = Str::studly($this->input->getArgument('name'));

        $modelName = $this->qualifyClass($this->getNameInput());

        $this->call('make:controller', array_filter([
            'name' => "{$controller}Controller",
            '--model' => $this->input->getOption('resource') || $this->input->getOption('api') ? $modelName : null,
            '--api' => $this->input->getOption('api'),
            '--requests' => $this->input->getOption('requests') || $this->input->getOption('all'),
        ]));
    }

    /**
     * Create the form requests for the model.
     *
     * @return void
     */
    protected function createFormRequests()
    {
        $request = Str::studly($this->input->getArgument('name'));

        $this->call('make:request', [
            'name' => "Store{$request}Request",
        ]);

        $this->call('make:request', [
            'name' => "Update{$request}Request",
        ]);
    }

    /**
     * Create a policy file for the model.
     *
     * @return void
     */
    protected function createPolicy()
    {
        $policy = Str::studly($this->input->getArgument('name'));

        $this->call('make:policy', [
            'name' => "{$policy}Policy",
            '--model' => $policy,
        ]);
    }


    protected function call(string $command, array $parameters = []): int
    {
        return $this->getApplication()->doRun(
            new ArrayInput(array_merge(['command' => $command], $parameters)),
            $this->output
        );
    }
}
