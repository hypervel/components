<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:install')]
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

        collect([
            'Service Provider' => fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'horizon-provider']) === 0,
            'Configuration' => fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'horizon-config']) === 0,
        ])->each(fn ($task, $description) => $this->components->task($description, $task));

        $this->registerHorizonServiceProvider();

        $this->components->info('Horizon scaffolding installed successfully.');
    }

    /**
     * Register the Horizon service provider in the application bootstrap file.
     */
    protected function registerHorizonServiceProvider(): void
    {
        $namespace = Str::replaceLast('\\', '', $this->hypervel->getNamespace());

        ServiceProvider::addProviderToBootstrapFile("{$namespace}\\Providers\\HorizonServiceProvider");

        file_put_contents(app_path('Providers/HorizonServiceProvider.php'), str_replace(
            'namespace App\Providers;',
            "namespace {$namespace}\\Providers;",
            file_get_contents(app_path('Providers/HorizonServiceProvider.php'))
        ));
    }
}
