<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Process;
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

        if (file_exists($apiRoutesPath = $this->hypervel->basePath('routes/api.php'))
            && ! $this->option('force')) {
            $this->components->error('API routes file already exists.');
        } else {
            $this->components->info('Published API routes file.');

            copy(__DIR__ . '/stubs/api-routes.stub', $apiRoutesPath);

            $this->uncommentApiRoutesFile();
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

        $content = file_get_contents($appBootstrapPath);

        if (str_contains($content, '// api: ')) {
            (new Filesystem)->replaceInFile(
                '// api: ',
                'api: ',
                $appBootstrapPath,
            );
        } elseif (str_contains($content, "web: __DIR__ . '/../routes/web.php',")) {
            (new Filesystem)->replaceInFile(
                "web: __DIR__ . '/../routes/web.php',",
                "web: __DIR__ . '/../routes/web.php'," . PHP_EOL . "        api: __DIR__ . '/../routes/api.php',",
                $appBootstrapPath,
            );
        } else {
            $this->components->warn("Unable to automatically add API route definition to [{$appBootstrapPath}]. API route file should be registered manually.");

            return;
        }
    }

    /**
     * Install Hypervel Sanctum into the application.
     */
    protected function installSanctum(): void
    {
        $this->requireComposerPackages((string) $this->option('composer'), [
            'hypervel/sanctum:^0.4',
        ]);

        $migrationPublished = (new Collection(scandir($this->hypervel->databasePath('migrations'))))->contains(function ($migration) {
            return preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_create_personal_access_tokens_table.php/', $migration);
        });

        if (! $migrationPublished) {
            Process::run([
                php_binary(),
                artisan_binary(),
                'vendor:publish',
                '--provider',
                'Hypervel\Sanctum\SanctumServiceProvider',
            ]);
        }
    }
}
