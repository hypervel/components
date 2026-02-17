<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\Prohibitable;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Seeder;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class SeedCommand extends Command
{
    use ConfirmableTrait;
    use Prohibitable;

    /**
     * The console command name.
     */
    protected ?string $name = 'db:seed';

    /**
     * The console command description.
     */
    protected string $description = 'Seed the database with records';

    /**
     * Create a new database seed command instance.
     *
     * @param ConnectionResolverInterface $resolver the connection resolver instance
     */
    public function __construct(
        protected ConnectionResolverInterface $resolver
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->isProhibited()
            || ! $this->confirmToProceed()
        ) {
            return Command::FAILURE;
        }

        $this->components->info('Seeding database.');

        $previousConnection = $this->resolver->getDefaultConnection();

        $this->resolver->setDefaultConnection($this->getDatabase());

        Model::unguarded(function () {
            $this->getSeeder()->__invoke();
        });

        if ($previousConnection) {
            $this->resolver->setDefaultConnection($previousConnection);
        }

        return 0;
    }

    /**
     * Get a seeder instance from the container.
     */
    protected function getSeeder(): Seeder
    {
        $class = $this->input->getArgument('class') ?? $this->input->getOption('class');

        if (! str_contains($class, '\\')) {
            $class = 'Database\Seeders\\' . $class;
        }

        if ($class === 'Database\Seeders\DatabaseSeeder'
            && ! class_exists($class)) {
            $class = 'DatabaseSeeder';
        }

        return $this->app->make($class)
            ->setContainer($this->app)
            ->setCommand($this);
    }

    /**
     * Get the name of the database connection to use.
     */
    protected function getDatabase(): string
    {
        $database = $this->input->getOption('database');

        return $database
            ?: $this->app->make('config')
                ->get('database.default');
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['class', InputArgument::OPTIONAL, 'The class name of the root seeder', null],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['class', null, InputOption::VALUE_OPTIONAL, 'The class name of the root seeder', 'Database\Seeders\DatabaseSeeder'],
            ['database', null, InputOption::VALUE_OPTIONAL, 'The database connection to seed'],
            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production'],
        ];
    }
}
