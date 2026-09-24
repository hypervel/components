<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

use Hypervel\Console\Command;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;
use Hypervel\Database\Migrations\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'migrate:install')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'migrate:install {--database= : The database connection to use}';

    /**
     * The console command description.
     */
    protected string $description = 'Create the migration repository';

    /**
     * The repository instance.
     */
    protected MigrationRepositoryInterface $repository;

    /**
     * Create a new migration install command instance.
     */
    public function __construct(MigrationRepositoryInterface $repository)
    {
        parent::__construct();

        $this->repository = $repository;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->repository->setSource(
            Migrator::resolveMigrationConnectionName($this->input->getOption('database'))
        );

        if (! $this->repository->repositoryExists()) {
            $this->repository->createRepository();
        }

        $this->components->info('Migration table created successfully.');
    }
}
