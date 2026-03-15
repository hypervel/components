<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Hypervel\Support\ServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Prompts\select;

#[AsCommand(name: 'config:publish')]
class ConfigPublishCommand extends Command
{
    protected ?string $signature = 'config:publish
                    {name? : The name of the configuration file to publish}
                    {--all : Publish all configuration files}
                    {--force : Overwrite any existing configuration files}';

    protected string $description = 'Publish configuration files to your application';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $config = $this->getPublishableConfigFiles();

        if (count($config) === 0) {
            $this->components->info('No publishable configuration files found.');

            return 0;
        }

        if (is_null($this->argument('name')) && $this->option('all')) {
            foreach ($config as $name => $file) {
                $this->publish($name, $file, $this->hypervel->configPath() . '/' . $name . '.php');
            }

            return 0;
        }

        $name = (string) (is_null($this->argument('name')) ? select(
            label: 'Which configuration file would you like to publish?',
            options: array_keys($config),
        ) : $this->argument('name'));

        if (! isset($config[$name])) {
            $this->components->error('Unrecognized configuration file.');

            return 1;
        }

        $this->publish($name, $config[$name], $this->hypervel->configPath() . '/' . $name . '.php');

        return 0;
    }

    /**
     * Publish the given file to the given destination.
     */
    protected function publish(string $name, string $file, string $destination): void
    {
        if (file_exists($destination) && ! $this->option('force')) {
            $this->components->error("The '{$name}' configuration file already exists.");

            return;
        }

        copy($file, $destination);

        $this->components->info("Published '{$name}' configuration file.");
    }

    /**
     * Get the publishable configuration files from registered service providers.
     *
     * @return array<string, string>
     */
    protected function getPublishableConfigFiles(): array
    {
        $paths = ServiceProvider::pathsToPublish(null, 'config');

        $config = [];

        foreach ($paths as $source => $destination) {
            $name = basename($source, '.php');
            $config[$name] = $source;
        }

        return (new Collection($config))->sortKeys()->all();
    }
}
