<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Closure;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\ColumnDefinition;
use Hypervel\Database\Schema\ForeignKeyDefinition;
use Hypervel\Database\Schema\IndexDefinition;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\PHPStan\Schema\BlueprintMacroExtension;
use Hypervel\PHPStan\Support\DirectoryResolver;
use Hypervel\PHPStan\Support\RecursiveDirectoryIterator;
use LogicException;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Container;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
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

/**
 * @phpstan-type BlueprintMacro array{owner: class-string, extension: BlueprintMacroExtension, returns: 'command'|'column'|'unknown', sources: list<string>}
 */
#[AutowiredService]
class MigrationHelper
{
    use HasContainer;

    /** @var null|array<string, null|string> */
    private ?array $migrationPaths = null;

    /** @var null|list<SplFileInfo> */
    private ?array $migrationFiles = null;

    /** @var null|array<string, BlueprintMacro> */
    private ?array $blueprintMacros = null;

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
        private Container $phpstanContainer,
    ) {
    }

    /**
     * Get descriptions that match the macro closures registered by the booted application.
     *
     * @return array<string, BlueprintMacro>
     */
    public function getBlueprintMacros(): array
    {
        if ($this->blueprintMacros !== null) {
            return $this->blueprintMacros;
        }

        $blueprint = new ReflectionClass(Blueprint::class);
        $closures = $blueprint->getProperty('macros')->getValue();
        $macros = [];

        foreach ($this->phpstanContainer->getServicesByTag(BlueprintMacroExtension::TAG) as $extension) {
            $sources = null;

            foreach ($extension->getMacros() as $name => $owner) {
                $closure = $closures[$name] ?? null;

                if (! $closure instanceof Closure || ($blueprint->hasMethod($name) && $blueprint->getMethod($name)->isPublic())) {
                    continue;
                }

                $reflection = new ReflectionFunction($closure);

                if ($reflection->getClosureScopeClass()?->getName() !== $owner) {
                    continue;
                }

                if (isset($macros[$name])) {
                    throw new LogicException(sprintf('Blueprint macro [%s] registered by [%s] is described by both [%s] and [%s].', $name, $owner, $macros[$name]['extension']::class, $extension::class));
                }

                $type = $reflection->getReturnType();
                $type = $type instanceof ReflectionNamedType ? $type->getName() : '';
                $returns = match (true) {
                    $type === 'void', is_a($type, ForeignKeyDefinition::class, true), is_a($type, IndexDefinition::class, true) => 'command',
                    is_a($type, ColumnDefinition::class, true) => 'column',
                    default => 'unknown',
                };
                $sources ??= SourceFiles::forInstance($this->reflectionProvider, $this->reflectionProvider->getClass($extension::class), null);
                $macros[$name] = ['owner' => $owner, 'extension' => $extension, 'returns' => $returns, 'sources' => $sources];
            }
        }

        return $this->blueprintMacros = $macros;
    }

    /**
     * Get the live Blueprint registrations and return categories that decide migration facts.
     */
    public function getSchemaConfiguration(): array
    {
        return ['blueprintMacros' => array_map(
            static fn (array $macro): array => [$macro['owner'], $macro['extension']::class, $macro['returns']],
            $this->getBlueprintMacros(),
        )];
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
            $this->parser,
            $this->getBlueprintMacros(),
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

        foreach ($this->connectionConfigResolver->getResolvedConnections() as $mapping) {
            $files = SourceFiles::forInstance($this->reflectionProvider, $this->reflectionProvider->getClass($mapping['service']), null);
            $schemaAggregator->sources += array_fill_keys($files, true);
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
