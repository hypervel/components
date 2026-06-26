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
    public function handle(): int
    {
        $this->components->info('Installing Horizon resources.');

        if (! $this->publishResource('Service Provider', 'horizon-provider')
            || ! $this->publishResource('Configuration', 'horizon-config')) {
            return self::FAILURE;
        }

        if (! $this->registerHorizonServiceProvider()) {
            return self::FAILURE;
        }

        $this->components->info('Horizon scaffolding installed successfully.');

        return self::SUCCESS;
    }

    /**
     * Publish a Horizon resource.
     */
    protected function publishResource(string $description, string $tag): bool
    {
        $published = false;

        $this->components->task($description, function () use (&$published, $tag): bool {
            return $published = $this->callSilent('vendor:publish', ['--tag' => $tag]) === 0;
        });

        if (! $published) {
            $this->components->error("Unable to publish Horizon {$description}.");
        }

        return $published;
    }

    /**
     * Register the Horizon service provider in the application bootstrap file.
     */
    protected function registerHorizonServiceProvider(): bool
    {
        $namespace = Str::replaceLast('\\', '', $this->hypervel->getNamespace());

        if (! ServiceProvider::addProviderToBootstrapFile("{$namespace}\\Providers\\HorizonServiceProvider")) {
            $this->components->error('Unable to register HorizonServiceProvider in bootstrap/providers.php.');

            return false;
        }

        $providerPath = $this->hypervel->path('Providers/HorizonServiceProvider.php');

        if (! is_file($providerPath) || ! is_readable($providerPath)) {
            $this->components->error('HorizonServiceProvider file was not published.');

            return false;
        }

        $contents = file_get_contents($providerPath);

        if ($contents === false) {
            $this->components->error('Unable to read the HorizonServiceProvider file.');

            return false;
        }

        if (file_put_contents($providerPath, str_replace(
            'namespace App\Providers;',
            "namespace {$namespace}\\Providers;",
            $contents,
        )) === false) {
            $this->components->error('Unable to update the HorizonServiceProvider namespace.');

            return false;
        }

        return true;
    }
}
