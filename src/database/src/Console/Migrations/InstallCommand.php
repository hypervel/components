<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

use Hypervel\Console\Command;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;

class InstallCommand extends Command
{
    protected ?string $signature = 'migrate:install
        {--database= : The database connection to use}';

    protected string $description = 'Create the migration repository';

    public function __construct(
        protected MigrationRepositoryInterface $repository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->repository->setSource($this->option('database'));

        if (! $this->repository->repositoryExists()) {
            $this->repository->createRepository();
        }

        $this->components->info('Migration table created successfully.');
    }
}
