<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\Prohibitable;
use Hypervel\Database\ConnectionResolverInterface;

class WipeCommand extends Command
{
    use ConfirmableTrait;
    use Prohibitable;

    protected ?string $signature = 'db:wipe
        {--database= : The database connection to use}
        {--drop-views : Drop all tables and views}
        {--drop-types : Drop all tables and types (Postgres only)}
        {--force : Force the operation to run when in production}';

    protected string $description = 'Drop all tables, views, and types';

    public function __construct(
        protected ConnectionResolverInterface $db
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->isProhibited() || ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $database = $this->option('database');

        if ($this->option('drop-views')) {
            $this->dropAllViews($database);

            $this->components->info('Dropped all views successfully.');
        }

        $this->dropAllTables($database);

        $this->components->info('Dropped all tables successfully.');

        if ($this->option('drop-types')) {
            $this->dropAllTypes($database);

            $this->components->info('Dropped all types successfully.');
        }

        return self::SUCCESS;
    }

    /**
     * Drop all of the database tables.
     */
    protected function dropAllTables(?string $database): void
    {
        $this->db->connection($database)
            ->getSchemaBuilder()
            ->dropAllTables();
    }

    /**
     * Drop all of the database views.
     */
    protected function dropAllViews(?string $database): void
    {
        $this->db->connection($database)
            ->getSchemaBuilder()
            ->dropAllViews();
    }

    /**
     * Drop all of the database types.
     */
    protected function dropAllTypes(?string $database): void
    {
        $this->db->connection($database)
            ->getSchemaBuilder()
            ->dropAllTypes();
    }
}
