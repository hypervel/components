<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Str;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'horizon:install';

    /**
     * The console command description.
     */
    protected string $description = 'Install all of the Horizon resources';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->components->info('Installing Horizon resources.');

        $this->components->task(
            'Service Provider and Configuration',
            fn () => $this->callSilent('vendor:publish', [
                'package' => 'Hypervel\Horizon\HorizonServiceProvider'
            ]) == 0
        );

        $this->registerHorizonServiceProvider();

        $this->components->info('Horizon scaffolding installed successfully.');
    }

    /**
     * Register the Horizon service provider in the application configuration file.
     */
    protected function registerHorizonServiceProvider(): void
    {
        $namespace = Str::replaceLast('\\', '', app()->getNamespace());

        $appConfig = file_get_contents(config_path('app.php'));

        if (Str::contains($appConfig, $namespace . '\Providers\HorizonServiceProvider::class')) {
            return;
        }

        file_put_contents(config_path('app.php'), str_replace(
            "{$namespace}\\Providers\\EventServiceProvider::class," . PHP_EOL,
            "{$namespace}\\Providers\\EventServiceProvider::class," . PHP_EOL . "        {$namespace}\\Providers\\HorizonServiceProvider::class," . PHP_EOL,
            $appConfig
        ));

        file_put_contents(app_path('Providers/HorizonServiceProvider.php'), str_replace(
            'namespace App\Providers;',
            "namespace {$namespace}\\Providers;",
            file_get_contents(app_path('Providers/HorizonServiceProvider.php'))
        ));
    }
}
