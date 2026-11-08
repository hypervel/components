<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Support\RecursiveDirectoryIterator;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_values;
use function database_path;
use function is_dir;
use function iterator_to_array;
use function str_ends_with;
use function uasort;

#[AutowiredService]
class MigrationHelper
{
    use HasContainer;

    /** @var null|array<string, null|string> */
    private ?array $migrationPaths = null;

    /** @var null|list<SplFileInfo> */
    private ?array $migrationFiles = null;

    /**
     * Create a new migration helper instance.
     */
    public function __construct(
        #[AutowiredParameter(ref: '@migrationsParser')]
        private Parser $parser,
        /** @var string[] */
        #[AutowiredParameter(ref: '%databaseMigrationsPath%')]
        private array $databaseMigrationPath,
        private FileHelper $fileHelper,
        #[AutowiredParameter]
        private bool $disableMigrationScan,
        #[AutowiredParameter(ref: '@reflectionProvider')]
        private ReflectionProvider $reflectionProvider,
        private InitializerExprTypeResolver $initializerExprTypeResolver,
        private ConnectionConfigResolver $connectionConfigResolver,
    ) {
    }

    /**
     * Apply the migrations to the schemas of the connections they run on.
     *
     * @param array<string, SchemaConnection> $connections the schemas loaded from dumps, by owning connection
     *
     * @return array<string, SchemaConnection>
     */
    public function initializeConnections(array $connections = []): array
    {
        return $this->aggregate($connections)->connections;
    }

    /**
     * Apply the migrations to the schemas of the connections they run on, keeping the PHP files they read.
     *
     * @param array<string, SchemaConnection> $connections the schemas loaded from dumps, by owning connection
     */
    public function aggregate(array $connections = []): SchemaAggregator
    {
        $schemaAggregator = new SchemaAggregator(
            $this->reflectionProvider,
            $this->initializerExprTypeResolver,
            $this->connectionConfigResolver,
            $connections,
        );

        foreach ($this->getMigrationFiles() as $file) {
            try {
                $schemaAggregator->addStatements($this->parser->parseFile($file->getPathname()));
            } catch (ParserErrorsException) {
                // Unlike upstream, which skipped the file, its changes are unknown.
                $schemaAggregator->invalidate();
            }
        }

        return $schemaAggregator;
    }

    /**
     * Get the migration files, in the order they run.
     *
     * @return list<SplFileInfo>
     */
    public function getMigrationFiles(): array
    {
        if ($this->migrationFiles !== null) {
            return $this->migrationFiles;
        }

        $paths = $this->getMigrationPaths();

        if ($this->databaseMigrationPath === [] && $paths !== []) {
            // Unlike upstream, default discovery is migrate's: a later path's migration replaces an
            // earlier one of the same name, so a published copy replaces its package's original.
            return $this->migrationFiles = array_map(
                static fn (string $file): SplFileInfo => new SplFileInfo($file),
                array_values($this->getContainer()->make('migrator')->getMigrationFiles(array_keys($paths))),
            );
        }

        $migrationFiles = [];

        foreach (array_keys($paths) as $directory) {
            if (is_dir($directory)) {
                $migrationFiles += iterator_to_array(
                    new RegexIterator(
                        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
                        '/\.php$/i',
                    ),
                );
            }
        }

        uasort($migrationFiles, static fn (SplFileInfo $a, SplFileInfo $b): int => $a->getFilename() <=> $b->getFilename());

        return $this->migrationFiles = array_values($migrationFiles);
    }

    /**
     * Get the paths migrations are discovered in, each mapped to the pattern its migration file names
     * match, or to null for a migration file.
     *
     * By default these are the paths migrate reads: those providers register through loadMigrationsFrom(),
     * then the application's migration directory, each holding its *_*.php files. Configured paths
     * replace them and are directories scanned recursively for .php files, as upstream does; see
     * DirectoryResolver::resolve().
     *
     * @return array<string, null|string>
     */
    public function getMigrationPaths(): array
    {
        if ($this->disableMigrationScan) {
            return [];
        }

        if ($this->migrationPaths !== null) {
            return $this->migrationPaths;
        }

        if ($this->databaseMigrationPath !== []) {
            return $this->migrationPaths = array_fill_keys(DirectoryResolver::resolve($this->databaseMigrationPath, $this->fileHelper), '*.[pP][hH][pP]');
        }

        $paths = [];

        foreach ([...$this->getContainer()->make('migrator')->paths(), database_path('migrations')] as $path) {
            $paths[$path] = str_ends_with($path, '.php') ? null : '*_*.php';
        }

        return $this->migrationPaths = $paths;
    }
}
