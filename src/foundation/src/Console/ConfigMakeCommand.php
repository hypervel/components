<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

use function Hypervel\Filesystem\join_paths;

#[AsCommand(name: 'make:config', aliases: ['config:make'])]
class ConfigMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:config';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new configuration file';

    /**
     * The type of file being generated.
     */
    protected string $type = 'Config';

    /**
     * The console command name aliases.
     *
     * @var string[]
     */
    protected array $aliases = ['config:make'];

    /**
     * Get the destination file path.
     */
    protected function getPath(string $name): string
    {
        return config_path(Str::finish($this->argument('name'), '.php'));
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        $relativePath = join_paths('stubs', 'config.stub');

        return file_exists($customPath = $this->hypervel->basePath($relativePath))
            ? $customPath
            : join_paths(__DIR__, $relativePath);
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the configuration file even if it already exists'],
        ];
    }

    /**
     * Prompt for missing input arguments using the returned questions.
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'name' => 'What should the configuration file be named?',
        ];
    }
}
