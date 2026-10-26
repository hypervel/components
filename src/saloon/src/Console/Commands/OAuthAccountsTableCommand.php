<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Console\Commands;

use Hypervel\Console\MigrationGeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:oauth-accounts-table', aliases: ['oauth-accounts:table'])]
class OAuthAccountsTableCommand extends MigrationGeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:oauth-accounts-table';

    /**
     * The console command name aliases.
     *
     * @var string[]
     */
    protected array $aliases = ['oauth-accounts:table'];

    /**
     * The console command description.
     */
    protected string $description = 'Create a migration for the OAuth accounts table';

    /**
     * Get the migration table name.
     */
    protected function migrationTableName(): string
    {
        return 'oauth_accounts';
    }

    /**
     * Get the path to the migration stub file.
     */
    protected function migrationStubFile(): string
    {
        return __DIR__ . '/stubs/oauth-accounts.stub';
    }
}
