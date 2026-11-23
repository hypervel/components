<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\PHPStan\Support\DirectoryResolver;
use Hypervel\SchemaParser\ParseException;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\File\FileHelper;
use SplFileInfo;

use function array_column;
use function array_key_exists;
use function array_keys;
use function database_path;
use function file_get_contents;
use function is_file;

/**
 * Reads the schema dumps that schema:dump writes for the booted connections.
 */
#[AutowiredService]
class SquashedMigrationHelper
{
    /**
     * Create a new squashed migration helper instance.
     *
     * @param string[] $schemaPaths
     */
    public function __construct(
        #[AutowiredParameter(ref: '%squashedMigrationsPath%')]
        private array $schemaPaths,
        private FileHelper $fileHelper,
        private ConnectionConfigResolver $connectionConfigResolver,
        #[AutowiredParameter]
        private bool $disableSchemaScan,
    ) {
    }

    /**
     * Read the schema dumps into the facts of the connections that own them.
     *
     * Unlike upstream, which merged every .dump and .sql file it found into one schema, a dump
     * belongs to the connection its name gives, and one that cannot be read leaves its owner's
     * relations unknown.
     *
     * @return array<string, SchemaConnection>
     */
    public function initializeConnections(): array
    {
        $connections = [];
        $uninterpreted = [];

        foreach ($this->findSchemaFiles() as [$name, $file]) {
            $connection = $this->connectionConfigResolver->connection($name);

            if ($connection !== null
                && ! $this->addDump($connections[$connection->owner] ??= new SchemaConnection($connection->owner, $connection->driver), $connection, $file)) {
                $uninterpreted[$connection->owner] = true;
            }
        }

        // A dump that could not be read may define any relation the owner's other dumps define.
        foreach (array_keys($uninterpreted) as $owner) {
            $connections[$owner]->forget();
        }

        return $connections;
    }

    /**
     * Add a schema dump's definitions to its owner's facts, or return false when the dump cannot be interpreted.
     *
     * Schema-level issues mean represented definitions may be wrong, so its tables are incomplete
     * and its types uncertain. Another dump defining the same relation, or a type differently,
     * makes it unknown.
     */
    private function addDump(SchemaConnection $owner, ConnectionConfig $connection, SplFileInfo $file): bool
    {
        $sql = file_get_contents($file->getPathname());
        $schema = null;
        $failure = $sql === false ? new ParseException('The dump could not be read') : null;

        if ($sql !== false) {
            try {
                $schema = $connection->createParser()?->parse($sql);
            } catch (ParseException $exception) {
                $failure = $exception;
            }
        }

        if ($failure !== null) {
            $owner->unusableDumps[] = ['path' => $file->getPathname(), 'line' => $failure->sqlLine ?? 1, 'message' => $failure->getMessage()];
        }

        if ($schema === null) {
            return false;
        }

        $complete = $schema->issues === [];
        $owner->complete = $owner->complete && $complete;
        $altered = [];

        foreach ($schema->impact->relations as $relation) {
            $altered[$owner->key($relation->schema ?? $connection->definitionSchema(), $relation->name)] = true;
        }

        foreach ($schema->tables as $definition) {
            $tableSchema = $definition->schema ?? $connection->definitionSchema();
            $key = $owner->key($tableSchema, $definition->name);

            // Dumps alter most tables they create, but an alteration the parser could not apply can leave a
            // column that no longer exists or has another type.
            if (isset($owner->tables[$key]) || (! $definition->isComplete() && isset($altered[$key]))) {
                $owner->forgetTable($tableSchema, $definition->name);
            } else {
                $owner->addTable($definition, $connection, $complete);
            }
        }

        foreach ($schema->views as $view) {
            $owner->forgetTable($view->schema ?? $connection->definitionSchema(), $view->name);
        }

        foreach ([...$schema->enums, ...$schema->domains] as $type) {
            $key = $owner->key($type->schema, $type->name);
            $owner->types[$key] = ! $complete || (array_key_exists($key, $owner->types) && $owner->types[$key] != $type) ? null : $type;
        }

        return true;
    }

    /**
     * Get the schema dumps of the booted connections, which the migration cache fingerprints.
     *
     * @return list<SplFileInfo>
     */
    public function getSchemaFiles(): array
    {
        return array_column($this->findSchemaFiles(), 1);
    }

    /**
     * Find each booted connection's schema dump in each schema directory, with the connection's name.
     *
     * As in MigrateCommand::schemaPath(), a connection's .dump file takes precedence over its .sql file.
     *
     * @return list<array{string, SplFileInfo}>
     */
    private function findSchemaFiles(): array
    {
        $files = [];

        foreach ($this->getSchemaDirectories() as $directory) {
            foreach ($this->connectionConfigResolver->getConnectionNames() as $name) {
                foreach (['dump', 'sql'] as $extension) {
                    $path = "{$directory}/{$name}-schema.{$extension}";

                    if (is_file($path)) {
                        $files[] = [$name, new SplFileInfo($path)];

                        break;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * The directories scanned for schema dumps, see DirectoryResolver::resolve().
     *
     * @return list<string>
     */
    public function getSchemaDirectories(): array
    {
        if ($this->disableSchemaScan) {
            return [];
        }

        if (empty($this->schemaPaths)) {
            $this->schemaPaths = [database_path('schema')];
        }

        return DirectoryResolver::resolve($this->schemaPaths, $this->fileHelper);
    }
}
