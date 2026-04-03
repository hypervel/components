<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Seeds;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:seeder')]
class SeederMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:seeder';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new seeder class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Seeder';

    /**
     * Execute the console command.
     */
    public function handle(): bool|int
    {
        return parent::handle();
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/seeder.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        return is_file($customPath = $this->hypervel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__ . $stub;
    }

    /**
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        $name = str_replace('\\', '/', Str::replaceFirst($this->rootNamespace(), '', $name));

        if (is_dir($this->hypervel->databasePath() . '/seeds')) {
            return $this->hypervel->databasePath() . '/seeds/' . $name . '.php';
        }

        return $this->hypervel->databasePath() . '/seeders/' . $name . '.php';
    }

    /**
     * Get the root namespace for the class.
     */
    protected function rootNamespace(): string
    {
        return 'Database\Seeders\\';
    }
}
