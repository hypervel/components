<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Properties\Schema\DataTypeToPhpTypeConverter;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\PHPStan\Support\ConfigSourceResolver;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Str;
use PDO;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use ReflectionException;
use ReflectionNamedType;

use function array_all;
use function array_combine;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function method_exists;
use function spl_object_id;
use function strtolower;
use function strtoupper;

#[AutowiredService]
class ModelPropertyHelper
{
    /** @var array<string, SchemaConnection> */
    private array $connections = [];

    private bool $migrationsLoaded = false;

    /** @var array<string, true> the PHP files besides the migrations that the schema facts were read from, except tables' own sources */
    private array $schemaSources = [];

    /** @var null|array<string, bool> the config sources of the schema facts, each mapped to whether it is a directory */
    private ?array $schemaConfigSources = null;

    /** @var null|array<string, bool> the sources of every booted config value, each mapped to whether it is a directory */
    private ?array $configSources = null;

    /** @var array<string, null|string> the connection names of models, by class */
    private array $connectionNames = [];

    /** @var array<string, null|Type> the declared return types of Attribute accessor methods, null when there is none */
    private array $attributeTypes = [];

    /** @var array<string, array<string, string>> the column names by fetched attribute name, per table and ATTR_CASE */
    private array $fetchedNames = [];

    /**
     * Create a new model property helper instance.
     */
    public function __construct(
        private TypeStringResolver $stringResolver,
        private MigrationHelper $migrationHelper,
        private SquashedMigrationHelper $squashedMigrationHelper,
        private ModelCastHelper $modelCastHelper,
        private ModelHelper $modelHelper,
        private MigrationCache $migrationCache,
        private DeclarationDependencyTracker $declarationDependencyTracker,
        private ReflectionProvider $reflectionProvider,
        private ConnectionConfigResolver $connectionConfigResolver,
        private DataTypeToPhpTypeConverter $converter,
        private ConfigSourceResolver $configSourceResolver,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Determine if a property name is accepted for a model type, as any name is for an abstract model.
     *
     * An abstract model, including Model itself and template bounds, stands for its subclasses, so any
     * name may be one of their attributes. ModelPropertyExtension does not claim these names.
     */
    public function acceptsPropertyName(Type $modelType, string $propertyName): TrinaryLogic
    {
        return TrinaryLogic::lazyExtremeIdentity(
            TypeUtils::flattenTypes($modelType),
            static function (Type $type) use ($propertyName): TrinaryLogic {
                $classReflections = $type->getObjectClassReflections();
                $isAbstractModel = static fn (ClassReflection $classReflection): bool => $classReflection->isAbstract() && $classReflection->is(Model::class);

                return $classReflections !== [] && array_all($classReflections, $isAbstractModel)
                    ? TrinaryLogic::createYes()
                    : $type->hasInstanceProperty($propertyName);
            },
        );
    }

    /**
     * Determine if the model has a database property, or maybe has one when its schema facts are unknown.
     */
    public function hasDatabaseProperty(ClassReflection $classReflection, string $propertyName): TrinaryLogic
    {
        if (! $classReflection->is(Model::class)) {
            return TrinaryLogic::createNo();
        }

        if (ReflectionHelper::hasPropertyTag($classReflection, $propertyName)) {
            return TrinaryLogic::createNo();
        }

        // Unlike upstream, a model without readable schema facts is not claimed to lack the property:
        // an abstract model stands for its subclasses, and an uninstantiable one has no known table.
        if ($classReflection->isAbstract()) {
            return TrinaryLogic::createMaybe();
        }

        try {
            $modelInstance = $this->modelHelper->getInstance($classReflection);
        } catch (ReflectionException) {
            return TrinaryLogic::createMaybe();
        }

        // HasAttributes::hasAttribute() accepts a cast key without a column, such as a class cast built from other columns.
        if ($propertyName === $modelInstance->getKeyName() || $this->modelCastHelper->hasCastEntry($classReflection, $propertyName)) {
            return TrinaryLogic::createYes();
        }

        $lookup = $this->findTable($classReflection, $modelInstance);

        // Without established facts, a property is not claimed to be missing.
        return $lookup === null ? TrinaryLogic::createMaybe() : $this->hasColumn($lookup, $propertyName);
    }

    /**
     * Determine if a table on the model's connection has a column, or whether it is unknown.
     *
     * Unlike upstream, the table is looked up on the model's connection rather than by name alone,
     * and a table missing from the facts is not claimed, since a query can name a table by an alias.
     */
    public function hasTableColumn(ClassReflection $classReflection, string $table, string $column): bool
    {
        try {
            $modelInstance = $this->modelHelper->getInstance($classReflection);
        } catch (ReflectionException) {
            return true;
        }

        $lookup = $this->findTable($classReflection, $modelInstance, $table);

        return $lookup === null || $lookup['table'] === null || ! $this->hasColumn($lookup, $column)->no();
    }

    /**
     * Get the schema dumps of the model's connection that could not be read.
     *
     * @return list<array{path: string, line: int, message: string}>
     */
    public function getUnusableDumps(ClassReflection $classReflection): array
    {
        if (! $classReflection->is(Model::class)) {
            return [];
        }

        try {
            $modelInstance = $this->modelHelper->getInstance($classReflection);
        } catch (ReflectionException) {
            return [];
        }

        return $this->findConnection($classReflection, $modelInstance)['schema']->unusableDumps ?? [];
    }

    /**
     * Get the names of the attributes a model fetches from its table, or null when they are not all known.
     *
     * @return null|list<string>
     */
    public function getDatabasePropertyNames(ClassReflection $classReflection): ?array
    {
        try {
            $model = $this->modelHelper->getInstance($classReflection);
        } catch (ReflectionException) {
            return null;
        }

        $lookup = $this->findTable($classReflection, $model);
        $table = $lookup['table'] ?? null;

        if ($table === null || ! $table->complete || $lookup['config']->customized) {
            return null;
        }

        $names = [];

        foreach ($lookup['config']->fetchOptions as $options) {
            $names = [...$names, ...array_keys($this->getFetchedNames($table, $options))];
        }

        return array_values(array_unique($names));
    }

    /**
     * Get the database property of a model, which also types the directions its accessors and mutators leave to Eloquent.
     */
    public function getDatabaseProperty(ClassReflection $classReflection, string $propertyName): ModelProperty
    {
        try {
            $modelInstance = $this->modelHelper->getInstance($classReflection);
        } catch (ReflectionException) {
            // Reachable through an accessor on an abstract or uninstantiable model, whose attributes are unknown.
            return new ModelProperty($classReflection, new MixedType, new MixedType);
        }

        $lookup = $this->findTable($classReflection, $modelInstance);
        $table = $lookup['table'] ?? null;
        $hasCast = $this->modelCastHelper->hasCastForProperty($classReflection, $propertyName);
        $cast = $this->modelCastHelper->getCastForProperty($classReflection, $propertyName);

        if ($propertyName === $modelInstance->getKeyName() && ! isset($table->columns[$propertyName])) {
            $keyType = $this->stringResolver->resolve($modelInstance->getKeyType());
            [$readableType, $writableType, $readNullable, $writeNullable] = [$keyType, $keyType, false, false];
        } else {
            // A virtual attribute behind an accessor has no column, so its raw value is whatever was assigned,
            // or null when nothing was.
            [$readableType, $writableType, $readNullable, $writeNullable] = $lookup === null || $table === null || $this->hasColumn($lookup, $propertyName)->no()
                ? [new MixedType, new MixedType, true, true]
                : $this->getColumnTypes($lookup, $table, $propertyName, $cast !== null && $this->modelCastHelper->readsEmptyStringAsNull($cast));
        }

        $date = $this->hasDate($classReflection, $modelInstance, $propertyName);

        if ($hasCast && $cast === null) {
            // A cast that cannot be determined may turn the attribute into anything.
            $readableType = new MixedType;
            $writableType = $date ? $this->modelCastHelper->getDateWriteableType() : new MixedType;
        } elseif ($date) {
            $readableType = $cast !== null
                ? $this->modelCastHelper->getReadableType($cast, $readableType)
                : $this->modelCastHelper->getDateType();
            // Eloquent runs fromDateTime() on date columns before applying any cast,
            // so the cast's setter type does not apply to assignments.
            $writableType = $this->modelCastHelper->getDateWriteableType();
        } elseif ($cast !== null) {
            $readableType = $this->modelCastHelper->getReadableType($cast, $readableType);
            $writableType = $this->modelCastHelper->getWriteableType($cast, $writableType);
        }

        if (($date || $cast !== null) && ($readNullable || $writeNullable)) {
            // A caster's get() and set() also handle null, so their declarations decide it.
            $caster = $cast === null ? null : $this->modelCastHelper->getCaster($cast);

            if ($readNullable && ! $caster?->is(CastsAttributes::class)) {
                $readableType = TypeCombinator::addNull($readableType);
            }

            if ($writeNullable && ($date || $caster === null)) {
                $writableType = TypeCombinator::addNull($writableType);
            }
        }

        return new ModelProperty(
            $classReflection,
            $readableType,
            $writableType,
            canChangeTypeAfterAssignment: ! $date && ! $hasCast,
        );
    }

    /**
     * Get the types a column attribute reads as and accepts, with whether a read may be null and whether a write may be.
     *
     * Raw types include null and the connection's fetch options; the attribute reads the column
     * each option set's ATTR_CASE fetches under that name, or null when none does.
     *
     * @param array{config: ConnectionConfig, schema: SchemaConnection, table: null|SchemaTable} $lookup
     *
     * @return array{Type, Type, bool, bool}
     */
    private function getColumnTypes(array $lookup, SchemaTable $table, string $propertyName, bool $castReadsEmptyStringAsNull): array
    {
        $config = $lookup['config'];

        if ($config->customized) {
            return [new MixedType, new MixedType, true, true];
        }

        $schema = $lookup['schema'];
        $searchPath = $table->searchPath ?? $config->searchPath;
        $writeColumn = $table->columns[$propertyName] ?? null;
        $readableTypes = [];
        $readNullable = false;

        foreach ($config->fetchOptions as $options) {
            $name = $this->getFetchedNames($table, $options)[$propertyName] ?? null;
            $column = $name === null ? null : $table->columns[$name];

            if ($column === null) {
                $readableTypes[] = $table->complete ? new NullType : new MixedType;
                $readNullable = true;

                continue;
            }

            $readableType = $column->type === null
                ? new MixedType
                : $this->converter->convert($schema->driver, $column->type, $column->options, $schema, $searchPath)['readable'];
            // The column decides null, for an unknown or unmapped type too, before the fetch options apply.
            $readableType = $column->nullable ? TypeCombinator::addNull($readableType) : TypeCombinator::remove($readableType, new NullType);
            $readableType = $this->converter->applyFetchOptions($readableType, $schema->driver, [$options]);
            $readableTypes[] = $readableType;
            // NULL_TO_STRING fetches a null column as an empty string, which such a cast still reads as null.
            $readNullable = $readNullable || ! $readableType->isNull()->no() || ($castReadsEmptyStringAsNull && $column->nullable);
        }

        if ($writeColumn?->type === null) {
            $writableType = new MixedType;
        } else {
            $writableType = $this->converter->convert($schema->driver, $writeColumn->type, $writeColumn->options, $schema, $searchPath)['writable'];
            $writableType = $writeColumn->nullable ? TypeCombinator::addNull($writableType) : $writableType;
        }

        // Without a known column, the attribute may hold null.
        return [TypeCombinator::union(...$readableTypes), $writableType, $readNullable, $writeColumn->nullable ?? true];
    }

    /**
     * Determine whether a found table has a column, either as written or as an option set fetches it,
     * or maybe has it when the table's columns are not all known.
     *
     * @param array{config: ConnectionConfig, schema: SchemaConnection, table: null|SchemaTable} $lookup
     */
    private function hasColumn(array $lookup, string $column): TrinaryLogic
    {
        $table = $lookup['table'];

        if ($table === null) {
            return TrinaryLogic::createNo();
        }

        if (isset($table->columns[$column])) {
            return TrinaryLogic::createYes();
        }

        foreach ($lookup['config']->fetchOptions as $options) {
            if (isset($this->getFetchedNames($table, $options)[$column])) {
                return TrinaryLogic::createYes();
            }
        }

        return $table->complete ? TrinaryLogic::createNo() : TrinaryLogic::createMaybe();
    }

    /**
     * Get a table's column names by the attribute names an option set's ATTR_CASE fetches them as.
     *
     * @param array<int, mixed> $options
     *
     * @return array<string, string>
     */
    private function getFetchedNames(SchemaTable $table, array $options): array
    {
        $case = $options[PDO::ATTR_CASE] ?? PDO::CASE_NATURAL;
        $key = spl_object_id($table) . ':' . $case;

        if (isset($this->fetchedNames[$key])) {
            return $this->fetchedNames[$key];
        }

        $names = array_keys($table->columns);

        return $this->fetchedNames[$key] = array_combine(
            array_map(static fn (string $name): string => match ($case) {
                PDO::CASE_LOWER => strtolower($name),
                PDO::CASE_UPPER => strtoupper($name),
                default => $name,
            }, $names),
            $names,
        );
    }

    /**
     * Find the configuration and schema facts of a model's connection, or return null when they are unknown.
     *
     * @return null|array{config: ConnectionConfig, schema: SchemaConnection}
     */
    private function findConnection(ClassReflection $classReflection, Model $model): ?array
    {
        if (! $this->migrationsLoaded()) {
            $this->loadMigrations();
        }

        $connectionName = $this->getConnectionName($classReflection, $model) ?? $this->connectionConfigResolver->getDefaultConnectionName();
        $config = $connectionName === null ? null : $this->connectionConfigResolver->connection($connectionName);
        $schema = $config === null ? null : $this->connections[$config->owner] ?? null;

        return $config === null || $schema === null ? null : ['config' => $config, 'schema' => $schema];
    }

    /**
     * Get the name of the model's connection, or null for the default one.
     *
     * An overriding getConnectionName() can read any config value, so every booted config source
     * becomes a dependency of the files depending on the model.
     */
    private function getConnectionName(ClassReflection $classReflection, Model $model): ?string
    {
        $className = $classReflection->getName();

        if (array_key_exists($className, $this->connectionNames)) {
            return $this->connectionNames[$className];
        }

        if ($classReflection->getNativeMethod('getConnectionName')->getDeclaringClass()->getName() !== Model::class) {
            $this->trackConfigSources(
                $classReflection,
                $this->configSources ??= $this->configSourceResolver->getAllSources($this->configSourceResolver->getBootedConfigDirectories()),
            );
        }

        return $this->connectionNames[$className] = $model->getConnectionName();
    }

    /**
     * Find a model's table in the schema facts of its connection, or return null when the facts are unknown.
     *
     * A null table means the table does not exist on a connection whose relations are all known. A found
     * table's sources become dependencies of the model, since its column facts were read from them.
     *
     * @return null|array{config: ConnectionConfig, schema: SchemaConnection, table: null|SchemaTable}
     */
    private function findTable(ClassReflection $classReflection, Model $model, ?string $table = null): ?array
    {
        $connection = $this->findConnection($classReflection, $model);

        if ($connection === null) {
            return null;
        }

        ['config' => $config, 'schema' => $schema] = $connection;
        [$tableSchema, $name] = $config->physicalTable($table ?? $model->getTable());
        $key = $schema->findTableKey($tableSchema, $name, $config->defaultSchema, $config->searchPath);

        if ($key === false || ($key === null && ! $schema->complete)) {
            return null;
        }

        $tableFacts = $key === null ? null : $schema->tables[$key];

        foreach (array_keys($tableFacts->sources ?? []) as $source) {
            $this->declarationDependencyTracker->trackFileDependency($classReflection, $this->runtimeInputs->sourcePath($source));
        }

        return ['config' => $config, 'schema' => $schema, 'table' => $tableFacts];
    }

    /**
     * Determine if an accessor or mutator handles the property, as a get{Name}Attribute() or set{Name}Attribute() method or an Attribute method.
     *
     * Unlike upstream, public Attribute methods and those without generics count, as Eloquent uses them too.
     */
    public function hasAccessor(ClassReflection $classReflection, string $propertyName): bool
    {
        return $classReflection->is(Model::class)
            && ($this->getAttributeType($classReflection, $propertyName) !== null
                || $classReflection->hasNativeMethod('get' . Str::studly($propertyName) . 'Attribute')
                || $classReflection->hasNativeMethod('set' . Str::studly($propertyName) . 'Attribute'));
    }

    /**
     * Get the property an accessor or mutator provides.
     *
     * Each direction follows Eloquent's precedence: the get{Name}Attribute() or set{Name}Attribute() method,
     * then the Attribute callback, then the database property with its casts. Unlike upstream, a direction
     * without a callback is not refused, since Eloquent then reads or writes the attribute itself.
     */
    public function getAccessor(ClassReflection $classReflection, string $propertyName): ModelProperty
    {
        $readableType = $this->getGetMutatorType($classReflection, $propertyName)
            ?? $this->getAttributeGetMutatorType($classReflection, $propertyName);
        $writableType = $this->getSetMutatorType($classReflection, $propertyName)
            ?? $this->getAttributeSetMutatorType($classReflection, $propertyName);

        if ($readableType !== null && $writableType !== null) {
            return new ModelProperty($classReflection, $readableType, $writableType);
        }

        $databaseProperty = $this->getDatabaseProperty($classReflection, $propertyName);

        return new ModelProperty(
            $classReflection,
            $readableType ?? $databaseProperty->getReadableType(),
            $writableType ?? $databaseProperty->getWritableType(),
            canChangeTypeAfterAssignment: $readableType === null && $writableType === null
                && $databaseProperty->canChangeTypeAfterAssignment(),
        );
    }

    /**
     * Get the type a get{Name}Attribute() accessor returns, or null when there is none.
     */
    public function getGetMutatorType(ClassReflection $classReflection, string $propertyName): ?Type
    {
        $method = 'get' . Str::studly($propertyName) . 'Attribute';

        return $classReflection->hasNativeMethod($method)
            ? $classReflection->getNativeMethod($method)->getVariants()[0]->getReturnType()
            : null;
    }

    /**
     * Get the type an Attribute accessor's get callback returns, or null when it has none.
     */
    public function getAttributeGetMutatorType(ClassReflection $classReflection, string $propertyName): ?Type
    {
        $type = $this->getAttributeType($classReflection, $propertyName)?->getTemplateType(Attribute::class, 'TGet');

        return $type instanceof NeverType ? null : $type;
    }

    /**
     * Get the type a set{Name}Attribute() mutator accepts, or null when there is none.
     */
    private function getSetMutatorType(ClassReflection $classReflection, string $propertyName): ?Type
    {
        $method = 'set' . Str::studly($propertyName) . 'Attribute';

        if (! $classReflection->hasNativeMethod($method)) {
            return null;
        }

        return ($classReflection->getNativeMethod($method)->getVariants()[0]->getParameters()[0] ?? null)?->getType()
            ?? new MixedType;
    }

    /**
     * Get the type an Attribute mutator's set callback accepts, or null when it has none.
     */
    private function getAttributeSetMutatorType(ClassReflection $classReflection, string $propertyName): ?Type
    {
        $type = $this->getAttributeType($classReflection, $propertyName)?->getTemplateType(Attribute::class, 'TSet');

        return $type instanceof NeverType ? null : $type;
    }

    /**
     * Get the declared return type of the property's Attribute method, or null when Eloquent would not use one.
     *
     * Like HasAttributes::hasAttributeMutator(), the method's native return type must name Attribute itself.
     * Private methods are excluded, since Eloquent cannot call them.
     */
    private function getAttributeType(ClassReflection $classReflection, string $propertyName): ?Type
    {
        $cacheKey = $classReflection->getCacheKey() . '-' . $propertyName;

        if (array_key_exists($cacheKey, $this->attributeTypes)) {
            return $this->attributeTypes[$cacheKey];
        }

        $method = Str::camel($propertyName);

        if (! $classReflection->hasNativeMethod($method) || $classReflection->getNativeMethod($method)->isPrivate()) {
            return $this->attributeTypes[$cacheKey] = null;
        }

        $returnType = $classReflection->getNativeReflection()->getMethod($method)->getReturnType();

        return $this->attributeTypes[$cacheKey] = $returnType instanceof ReflectionNamedType && $returnType->getName() === Attribute::class
            ? $classReflection->getNativeMethod($method)->getVariants()[0]->getReturnType()
            : null;
    }

    /**
     * Determine whether the schema facts are loaded.
     */
    private function migrationsLoaded(): bool
    {
        return $this->migrationsLoaded;
    }

    /**
     * Load the schema facts from the schema dumps and migrations.
     */
    private function loadMigrations(): void
    {
        $this->migrationsLoaded = true;

        $migrationFiles = $this->migrationHelper->getMigrationFiles();
        $schemaFiles = $this->squashedMigrationHelper->getSchemaFiles();

        ['connections' => $this->connections, 'sources' => $this->schemaSources] = $this->migrationCache->remember(
            $migrationFiles,
            $schemaFiles,
            $this->connectionConfigResolver->getSchemaConfiguration(),
            function (): array {
                // Migrations apply on top of the schema dumps, as migrate loads a dump first.
                $aggregator = $this->migrationHelper->aggregate($this->squashedMigrationHelper->initializeConnections());

                return ['connections' => $aggregator->connections, 'sources' => $aggregator->sources];
            },
        );

        // Every file using a model depends on Model, so the dependency is declared once, on Model itself.
        // The config keys the facts read are known only now.
        $this->trackSchemaDependency($this->reflectionProvider->getClass(Model::class));
    }

    /**
     * Track the schema inputs as dependencies of the class.
     *
     * What the class declares depends on the database schema, which comes from
     * the migrations, the schema dumps, the config they read and the PHP files
     * naming their tables and connections. Each file depending on the class is
     * analyzed again when one of them is created, changed or deleted. The files
     * a table's columns were read from are tracked on the models reading it.
     */
    public function trackSchemaDependency(ClassReflection $classReflection): void
    {
        if (! $this->migrationsLoaded()) {
            $this->loadMigrations();
        }

        // The booted configuration and migrator choose the connections and migration paths.
        $this->runtimeInputs->track($classReflection);

        foreach ($this->migrationHelper->getMigrationPaths() as $path => $pattern) {
            if ($pattern === null) {
                $this->declarationDependencyTracker->trackFileDependency($classReflection, $this->runtimeInputs->sourcePath($path));
            } else {
                $this->declarationDependencyTracker->trackDirectoryDependency($classReflection, $this->runtimeInputs->sourceDirectory($path, $pattern), $pattern);
            }
        }

        foreach ($this->squashedMigrationHelper->getSchemaDirectories() as $directory) {
            $this->declarationDependencyTracker->trackDirectoryDependency($classReflection, $this->runtimeInputs->sourceDirectory($directory));
        }

        foreach (array_keys($this->schemaSources) as $source) {
            $this->declarationDependencyTracker->trackFileDependency($classReflection, $this->runtimeInputs->sourcePath($source));
        }

        $this->trackConfigSources($classReflection, $this->getSchemaConfigSources());
    }

    /**
     * Track config sources, each mapped to whether it is a directory of config files, as dependencies of the class.
     *
     * @param array<string, bool> $sources
     */
    private function trackConfigSources(ClassReflection $classReflection, array $sources): void
    {
        foreach ($sources as $path => $directory) {
            if ($directory) {
                $this->declarationDependencyTracker->trackDirectoryDependency(
                    $classReflection,
                    $this->runtimeInputs->sourceDirectory($path, ConfigSourceResolver::CONFIG_FILE_PATTERN),
                    ConfigSourceResolver::CONFIG_FILE_PATTERN,
                );
            } else {
                $this->declarationDependencyTracker->trackFileDependency($classReflection, $this->runtimeInputs->sourcePath($path));
            }
        }
    }

    /**
     * Get the paths whose contents can change the config the schema facts read, each mapped to
     * whether it is a directory.
     *
     * The facts read the booted configuration, so its sources are the booted config directories,
     * even when the analysis types config from others.
     *
     * @return array<string, bool>
     */
    private function getSchemaConfigSources(): array
    {
        if ($this->schemaConfigSources !== null) {
            return $this->schemaConfigSources;
        }

        $sources = [];
        $directories = $this->configSourceResolver->getBootedConfigDirectories();

        foreach ($this->connectionConfigResolver->getConsumedKeys() as $key) {
            $sources += $this->configSourceResolver->getKeySources($key, $directories);
        }

        return $this->schemaConfigSources = $sources;
    }

    /**
     * Determine whether the model treats the attribute as a date.
     */
    private function hasDate(ClassReflection $classReflection, Model $modelInstance, string $propertyName): bool
    {
        $dates = $modelInstance->getDates();

        // In order to support SoftDeletes
        // Hypervel: initializeSoftDeletes() adds its datetime cast only when the column has no cast,
        // including one an undetermined casts() entry may hold.
        if (method_exists($modelInstance, 'getDeletedAtColumn')
            && ! $this->modelCastHelper->hasCastForProperty($classReflection, $modelInstance->getDeletedAtColumn())) {
            $dates[] = $modelInstance->getDeletedAtColumn();
        }

        return in_array($propertyName, $dates, true);
    }
}
