<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Closure;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;
use InvalidArgumentException;

class MigrationCreator
{
    /**
     * The registered post create hooks.
     */
    protected array $postCreate = [];

    /**
     * Create a new migration creator instance.
     */
    public function __construct(
        protected Filesystem $files,
        protected ?string $customStubPath = null
    ) {
    }

    /**
     * Create a new migration at the given path.
     *
     * @throws \Exception
     */
    public function create(string $name, string $path, ?string $table = null, bool $create = false): string
    {
        $this->ensureMigrationDoesntAlreadyExist($name, $path);

        // First we will get the stub file for the migration, which serves as a type
        // of template for the migration. Once we have those we will populate the
        // various place-holders, save the file, and run the post create event.
        $stub = $this->getStub($table, $create);

        $path = $this->getPath($name, $path);

        $this->files->ensureDirectoryExists(dirname($path));

        $this->files->put(
            $path, $this->populateStub($stub, $table)
        );

        // Next, we will fire any hooks that are supposed to fire after a migration is
        // created. Once that is done we'll be ready to return the full path to the
        // migration file so it can be used however it's needed by the developer.
        $this->firePostCreateHooks($table, $path);

        return $path;
    }

    /**
     * Ensure that a migration with the given name doesn't already exist.
     *
     * @throws \InvalidArgumentException
     */
    protected function ensureMigrationDoesntAlreadyExist(string $name, ?string $migrationPath = null): void
    {
        if (! empty($migrationPath)) {
            $migrationFiles = $this->files->glob($migrationPath.'/*.php');

            foreach ($migrationFiles as $migrationFile) {
                $this->files->requireOnce($migrationFile);
            }
        }

        if (class_exists($className = $this->getClassName($name))) {
            throw new InvalidArgumentException("A {$className} class already exists.");
        }
    }

    /**
     * Get the migration stub file.
     */
    protected function getStub(?string $table, bool $create): string
    {
        if (is_null($table)) {
            $stub = $this->files->exists($customPath = $this->customStubPath.'/migration.stub')
                ? $customPath
                : $this->stubPath().'/migration.stub';
        } elseif ($create) {
            $stub = $this->files->exists($customPath = $this->customStubPath.'/migration.create.stub')
                ? $customPath
                : $this->stubPath().'/migration.create.stub';
        } else {
            $stub = $this->files->exists($customPath = $this->customStubPath.'/migration.update.stub')
                ? $customPath
                : $this->stubPath().'/migration.update.stub';
        }

        return $this->files->get($stub);
    }

    /**
     * Populate the place-holders in the migration stub.
     */
    protected function populateStub(string $stub, ?string $table): string
    {
        // Here we will replace the table place-holders with the table specified by
        // the developer, which is useful for quickly creating a tables creation
        // or update migration from the console instead of typing it manually.
        if (! is_null($table)) {
            $stub = str_replace(
                ['DummyTable', '{{ table }}', '{{table}}'],
                $table, $stub
            );
        }

        return $stub;
    }

    /**
     * Get the class name of a migration name.
     */
    protected function getClassName(string $name): string
    {
        return Str::studly($name);
    }

    /**
     * Get the full path to the migration.
     */
    protected function getPath(string $name, string $path): string
    {
        return $path.'/'.$this->getDatePrefix().'_'.$name.'.php';
    }

    /**
     * Fire the registered post create hooks.
     */
    protected function firePostCreateHooks(?string $table, string $path): void
    {
        foreach ($this->postCreate as $callback) {
            $callback($table, $path);
        }
    }

    /**
     * Register a post migration create hook.
     */
    public function afterCreate(Closure $callback): void
    {
        $this->postCreate[] = $callback;
    }

    /**
     * Get the date prefix for the migration.
     */
    protected function getDatePrefix(): string
    {
        return date('Y_m_d_His');
    }

    /**
     * Get the path to the stubs.
     */
    public function stubPath(): string
    {
        return __DIR__.'/stubs';
    }

    /**
     * Get the filesystem instance.
     */
    public function getFilesystem(): Filesystem
    {
        return $this->files;
    }
}
