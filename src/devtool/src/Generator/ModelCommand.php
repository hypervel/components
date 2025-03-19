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

    protected function call(string $command, array $parameters = []): int
    {
        return $this->getApplication()->doRun(
            new ArrayInput(array_merge(['command' => $command], $parameters)),
            $this->output
        );
    }
}
