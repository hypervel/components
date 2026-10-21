<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Concerns\CreatesMatchingTest;
use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Stringable;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:command')]
class ConsoleMakeCommand extends GeneratorCommand
{
    use CreatesMatchingTest;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:command
                    {name : The name of the command}
                    {--f|force : Create the class even if the console command already exists}
                    {--command= : The terminal command that will be used to invoke the class}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new Artisan command';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Console command';

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $stub = parent::replaceClass($stub, $name);

        $command = $this->option('command') ?: 'app:' . (new Stringable($name))->classBasename()->kebab()->value();

        return str_replace(['dummy:command', '{{ command }}'], $command, $stub);
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        $relativePath = '/stubs/console.stub';

        return file_exists($customPath = $this->hypervel->basePath(trim($relativePath, '/')))
            ? $customPath
            : __DIR__ . $relativePath;
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Console\Commands';
    }
}
