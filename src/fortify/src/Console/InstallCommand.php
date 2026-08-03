<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'fortify:install')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'fortify:install';

    /**
     * The console command description.
     */
    protected string $description = 'Install all of the Fortify resources';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Installing Fortify resources.');

        if (! $this->publishResource('Configuration', 'fortify-config')
            || ! $this->publishResource('Support', 'fortify-support')
            || ! $this->publishResource('Migrations', 'fortify-migrations')) {
            return self::FAILURE;
        }

        if (! $this->registerFortifyServiceProvider()) {
            return self::FAILURE;
        }

        $this->components->info('Fortify scaffolding installed successfully.');

        return self::SUCCESS;
    }

    /**
     * Publish a Fortify resource.
     */
    protected function publishResource(string $description, string $tag): bool
    {
        $published = false;

        $this->components->task($description, function () use (&$published, $tag): bool {
            return $published = $this->callSilent('vendor:publish', ['--tag' => $tag]) === self::SUCCESS;
        });

        if (! $published) {
            $this->components->error("Unable to publish Fortify {$description}.");
        }

        return $published;
    }

    /**
     * Register the Fortify service provider in the application bootstrap file.
     */
    protected function registerFortifyServiceProvider(): bool
    {
        $namespace = Str::replaceLast('\\', '', $this->hypervel->getNamespace());

        if (! $this->updatePublishedNamespaces($namespace)) {
            return false;
        }

        if (! ServiceProvider::addProviderToBootstrapFile("{$namespace}\\Providers\\FortifyServiceProvider")) {
            $this->components->error('Unable to register FortifyServiceProvider in bootstrap/providers.php.');

            return false;
        }

        return true;
    }

    /**
     * Update published support files to the application's namespace.
     */
    protected function updatePublishedNamespaces(string $namespace): bool
    {
        $filesystem = $this->hypervel->make(Filesystem::class);

        $paths = [
            $this->hypervel->path('Actions/Fortify/CreateNewUser.php'),
            $this->hypervel->path('Actions/Fortify/PasswordValidationRules.php'),
            $this->hypervel->path('Actions/Fortify/ResetUserPassword.php'),
            $this->hypervel->path('Actions/Fortify/UpdateUserPassword.php'),
            $this->hypervel->path('Actions/Fortify/UpdateUserProfileInformation.php'),
            $this->hypervel->path('Providers/FortifyServiceProvider.php'),
        ];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                $this->components->error("Fortify file [{$path}] was not published.");

                return false;
            }

            $contents = @file_get_contents($path);
            $permissions = @fileperms($path);

            if ($contents === false || $permissions === false) {
                $this->components->error("Unable to read published Fortify file [{$path}].");

                return false;
            }

            $contents = str_replace(
                ['namespace App\\', 'use App\\'],
                ["namespace {$namespace}\\", "use {$namespace}\\"],
                $contents,
            );

            try {
                $filesystem->replace($path, $contents, $permissions & 0777);
            } catch (RuntimeException) {
                $this->components->error("Unable to update published Fortify file [{$path}].");

                return false;
            }
        }

        return true;
    }
}
