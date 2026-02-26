<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Carbon\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:cache-table')]
class CacheTableCommand extends DevtoolGeneratorCommand
{
    protected ?string $name = 'make:cache-table';

    protected string $description = 'Create a migration for the cache database table';

    protected string $type = 'Migration';

    public function handle(): int
    {
        $tableName = $this->migrationTableName();
        $filename = Carbon::now()->format('Y_m_d_000000') . "_create_{$tableName}_table.php";
        $path = $this->option('path') ?: "database/migrations/{$filename}";

        // First we will check to see if the class already exists. If it does, we don't want
        // to create the class and overwrite the user's code. So, we will bail out so the
        // code is untouched. Otherwise, we will continue generating this class' files.
        if ((! $this->hasOption('force') || ! $this->option('force'))
            && $this->alreadyExists($path)) {
            $this->components->error($path . ' already exists!');
            return self::FAILURE;
        }

        // Next, we will generate the path to the location where this class' file should get
        // written. Then, we will build the class and make the proper replacements on the
        // stub files so that it gets the correctly formatted namespace and class name.
        $this->makeDirectory($path);

        $stub = file_get_contents($this->getStub());
        file_put_contents($path, $this->buildMigration($stub, $tableName));

        $this->components->info(sprintf('Migration [%s] created successfully.', $path));

        $this->openWithIde($path);

        return self::SUCCESS;
    }

    protected function buildMigration(string $stub, string $name): string
    {
        return str_replace('%TABLE%', $name, $stub);
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/cache-table.stub';
    }

    protected function alreadyExists(string $rawName): bool
    {
        return is_file(BASE_PATH . "/{$rawName}");
    }

    protected function getArguments(): array
    {
        return [];
    }

    protected function getOptions(): array
    {
        $options = array_filter(parent::getOptions(), function ($item) {
            return $item[0] !== 'path';
        });

        return array_merge(array_values($options), [
            ['path', 'p', InputOption::VALUE_OPTIONAL, 'The path of the cache table migration.'],
        ]);
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return '';
    }

    /**
     * Get the migration table name.
     */
    protected function migrationTableName(): string
    {
        return $this->app->make('config')
            ->get('cache.stores.database.table', 'cache');
    }
}
