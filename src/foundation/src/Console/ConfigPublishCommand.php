<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Finder\Finder;

use function Hypervel\Prompts\select;

#[AsCommand(name: 'config:publish')]
class ConfigPublishCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'config:publish
                    {name? : The name of the configuration file to publish}
                    {--all : Publish all configuration files}
                    {--force : Overwrite any existing configuration files}';

    /**
     * The console command description.
     */
    protected string $description = 'Publish configuration files to your application';

    /**
     * Execute the console command.
     */
    public function handle(): ?int
    {
        $config = $this->getBaseConfigurationFiles();

        if (is_null($this->argument('name')) && $this->option('all')) {
            foreach ($config as $key => $file) {
                $this->publish($key, $file, $this->hypervel->configPath() . '/' . $key . '.php');
            }

            return self::SUCCESS;
        }

        $name = (string) (is_null($this->argument('name')) ? select(
            label: 'Which configuration file would you like to publish?',
            options: array_keys($config),
        ) : $this->argument('name'));

        if (! isset($config[$name])) {
            $this->components->error('Unrecognized configuration file.');

            return self::FAILURE;
        }

        $this->publish($name, $config[$name], $this->hypervel->configPath() . '/' . $name . '.php');

        return self::SUCCESS;
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
     * Get an array containing the base configuration files.
     *
     * @return array<string, string>
     */
    protected function getBaseConfigurationFiles(): array
    {
        $config = [];

        $shouldMergeConfiguration = $this->hypervel->shouldMergeFrameworkConfiguration();

        foreach (Finder::create()->files()->name('*.php')->in(__DIR__ . '/../../config') as $file) {
            $name = basename($file->getRealPath(), '.php');

            $config[$name] = ($shouldMergeConfiguration === true && file_exists($stubPath = (__DIR__ . '/../../config-stubs/' . $name . '.php')))
                ? $stubPath
                : $file->getRealPath();
        }

        return (new Collection($config))->sortKeys()->all();
    }
}
