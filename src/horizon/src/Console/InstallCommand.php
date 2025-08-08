<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:install';

    /**
     * The console command description.
     */
    protected string $description = 'Install all of the Horizon resources';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->components->info('Installing Horizon resources.');

        collect([
            'Service Provider' => fn () => $this->callSilent('vendor:publish', ['--tag' => 'horizon-provider']) == 0,
            'Configuration' => fn () => $this->callSilent('vendor:publish', ['--tag' => 'horizon-config']) == 0,
        ])->each(fn ($task, $description) => $this->components->task($description, $task));

        $this->registerHorizonServiceProvider();

        $this->components->info('Horizon scaffolding installed successfully.');
    }

    /**
     * Register the Horizon service provider in the application configuration file.
     *
     * @return void
     */
    protected function registerHorizonServiceProvider()
    {
        $namespace = Str::replaceLast('\\', '', $this->laravel->getNamespace());

        if (file_exists($this->laravel->bootstrapPath('providers.php'))) {
            ServiceProvider::addProviderToBootstrapFile("{$namespace}\\Providers\\HorizonServiceProvider");
        } else {
            $appConfig = file_get_contents(config_path('app.php'));

            if (Str::contains($appConfig, $namespace.'\\Providers\\HorizonServiceProvider::class')) {
                return;
            }

            file_put_contents(config_path('app.php'), str_replace(
                "{$namespace}\\Providers\EventServiceProvider::class,".PHP_EOL,
                "{$namespace}\\Providers\EventServiceProvider::class,".PHP_EOL."        {$namespace}\Providers\HorizonServiceProvider::class,".PHP_EOL,
                $appConfig
            ));
        }

        file_put_contents(app_path('Providers/HorizonServiceProvider.php'), str_replace(
            "namespace App\Providers;",
            "namespace {$namespace}\Providers;",
            file_get_contents(app_path('Providers/HorizonServiceProvider.php'))
        ));
    }
}
