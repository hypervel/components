<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Closure;
use Exception;
use Hypervel\Context\CoroutineContext;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class MigrationCreator
{
    protected const CURRENT_MIGRATION_PATH_CONTEXT_KEY_PREFIX = '__database.migration_creator.current_path.';

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
     * @throws Exception
     */
    public function create(string $name, string $path, ?string $table = null, bool $create = false, ?string $stubPath = null): string
    {
        $this->ensureMigrationDoesntAlreadyExist($name, $path);

        // First we will get the stub file for the migration, which serves as a type
        // of template for the migration. Once we have those we will populate the
        // various place-holders, save the file, and run the post create event.
        $stub = $stubPath === null
            ? $this->getStub($table, $create)
            : $this->files->get($stubPath);

        $path = $this->getCollisionFreePath($name, $path);

        $this->files->ensureDirectoryExists(dirname($path));

        $this->files->replace(
            $path,
            $this->populateStub($stub, $table)
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
     * @throws InvalidArgumentException
     */
    protected function ensureMigrationDoesntAlreadyExist(string $name, ?string $migrationPath = null): void
    {
        if (! empty($migrationPath)) {
            $migrationFiles = $this->matchingFiles($migrationPath . '/*.php');

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
            $stub = $this->files->exists($customPath = $this->customStubPath . '/migration.stub')
                ? $customPath
                : $this->stubPath() . '/migration.stub';
        } elseif ($create) {
            $stub = $this->files->exists($customPath = $this->customStubPath . '/migration.create.stub')
                ? $customPath
                : $this->stubPath() . '/migration.create.stub';
        } else {
            $stub = $this->files->exists($customPath = $this->customStubPath . '/migration.update.stub')
                ? $customPath
                : $this->stubPath() . '/migration.update.stub';
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
                $table,
                $stub
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
        return $path . '/' . $this->getDatePrefix() . '_' . $name . '.php';
    }

    /**
     * Get a collision-free full path to the migration.
     */
    protected function getCollisionFreePath(string $name, string $path): string
    {
        $contextKey = self::CURRENT_MIGRATION_PATH_CONTEXT_KEY_PREFIX . spl_object_id($this);

        CoroutineContext::set($contextKey, $path);

        try {
            return $this->getPath($name, $path);
        } finally {
            CoroutineContext::forget($contextKey);
        }
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
        $path = CoroutineContext::get(
            self::CURRENT_MIGRATION_PATH_CONTEXT_KEY_PREFIX . spl_object_id($this)
        );

        if ($path === null) {
            return Date::now()->format('Y_m_d_His');
        }

        $date = Date::now();

        while ($this->matchingFiles($path . '/' . $date->format('Y_m_d_His') . '_*.php')) {
            $date = $date->addSecond();
        }

        return $date->format('Y_m_d_His');
    }

    /**
     * Get files matching the given pattern.
     *
     * @return list<string>
     */
    protected function matchingFiles(string $pattern): array
    {
        $files = $this->files->glob($pattern);

        if ($files === false) {
            throw new RuntimeException("Unable to read files matching [{$pattern}].");
        }

        return array_values($files);
    }

    /**
     * Get the path to the stubs.
     */
    public function stubPath(): string
    {
        return __DIR__ . '/stubs';
    }

    /**
     * Get the filesystem instance.
     */
    public function getFilesystem(): Filesystem
    {
        return $this->files;
    }
}
