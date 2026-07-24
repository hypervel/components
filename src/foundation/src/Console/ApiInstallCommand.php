<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Support\artisan_binary;
use function Hypervel\Support\php_binary;

#[AsCommand(name: 'install:api')]
class ApiInstallCommand extends Command
{
    use InteractsWithComposerPackages;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'install:api
                    {--composer=global : Absolute path to the Composer binary which should be used to install packages}
                    {--force : Overwrite any existing API routes file}
                    {--without-migration-prompt : Do not prompt to run pending migrations}';

    /**
     * The console command description.
     */
    protected string $description = 'Create an API routes file and install Hypervel Sanctum';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->installSanctum();
        $files = $this->hypervel->make(Filesystem::class);
        $apiRoutesPath = $this->hypervel->basePath('routes/api.php');

        if ($files->exists($apiRoutesPath)
            && ! $this->option('force')) {
            $this->components->error('API routes file already exists.');
        } else {
            $files->ensureDirectoryExists(dirname($apiRoutesPath));

            $mode = null;

            if ($files->exists($apiRoutesPath)) {
                $permissions = $files->chmod($apiRoutesPath);

                if ($permissions === false) {
                    throw new RuntimeException("Unable to determine permissions for [{$apiRoutesPath}].");
                }

                $mode = octdec($permissions);
            }

            $files->replace($apiRoutesPath, $files->get(__DIR__ . '/stubs/api-routes.stub'), $mode);

            $this->uncommentApiRoutesFile();

            $this->components->info('Published API routes file.');
        }

        if (! $this->option('without-migration-prompt')) {
            if ($this->confirm('One new database migration has been published. Would you like to run all pending database migrations?', true)) {
                $this->call('migrate');
            }
        }

        $this->components->info('API scaffolding installed. Please add the [Hypervel\Sanctum\HasApiTokens] trait to your User model.');
    }

    /**
     * Uncomment the API routes file in the application bootstrap file.
     */
    protected function uncommentApiRoutesFile(): void
    {
        $appBootstrapPath = $this->hypervel->bootstrapPath('app.php');
        $files = $this->hypervel->make(Filesystem::class);

        $content = $files->get($appBootstrapPath);

        if (str_contains($content, '// api: ')) {
            $content = str_replace(
                '// api: ',
                'api: ',
                $content,
            );
        } elseif (str_contains($content, "web: __DIR__ . '/../routes/web.php',")) {
            $content = str_replace(
                "web: __DIR__ . '/../routes/web.php',",
                "web: __DIR__ . '/../routes/web.php'," . PHP_EOL . "        api: __DIR__ . '/../routes/api.php',",
                $content,
            );
        } else {
            $this->components->warn("Unable to automatically add API route definition to [{$appBootstrapPath}]. API route file should be registered manually.");

            return;
        }

        $permissions = $files->chmod($appBootstrapPath);

        if ($permissions === false) {
            throw new RuntimeException("Unable to determine permissions for [{$appBootstrapPath}].");
        }

        $files->replace($appBootstrapPath, $content, octdec($permissions));
    }

    /**
     * Install Hypervel Sanctum into the application.
     */
    protected function installSanctum(): void
    {
        $this->requireComposerPackages((string) $this->option('composer'), [
            'hypervel/sanctum:^0.4',
        ]);

        $migrationPath = $this->hypervel->databasePath('migrations');
        $migrations = @scandir($migrationPath);

        if ($migrations === false) {
            throw new RuntimeException("Unable to read migration directory [{$migrationPath}].");
        }

        $migrationPublished = (new Collection($migrations))->contains(
            static fn (string $migration): bool => preg_match(
                '/\d{4}_\d{2}_\d{2}_\d{6}_create_personal_access_tokens_table.php/',
                $migration
            ) === 1
        );

        if (! $migrationPublished) {
            Process::run([
                php_binary(),
                artisan_binary(),
                'vendor:publish',
                '--provider',
                'Hypervel\Sanctum\SanctumServiceProvider',
            ])->throw();
        }
    }
}
