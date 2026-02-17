<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Carbon\Carbon;
use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class NotificationTableCommand extends GeneratorCommand
{
    protected ?string $name = 'make:notifications-table|notifications:table';

    protected string $description = 'Create a migration for the notifications table';

    protected string $type = 'Migration';

    public function handle(): int
    {
        $filename = Carbon::now()->format('Y_m_d_000000') . '_create_notifications_table.php';
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

        file_put_contents($path, file_get_contents($this->getStub()));

        $this->components->info(sprintf('Migration [%s] created successfully.', $path));

        $this->openWithIde($path);

        return self::SUCCESS;
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/notifications-table.stub';
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
            ['path', 'p', InputOption::VALUE_OPTIONAL, 'The path of the notifications table migration.'],
        ]);
    }

    protected function getDefaultNamespace(): string
    {
        return '';
    }
}
