<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ArrayAccess;
use BackedEnum;
use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonInterface;
use DateTime;
use DateTimeInterface;
use JsonSerializable;
use LogicException;
use OutOfBoundsException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;

abstract class DataObject implements ArrayAccess, JsonSerializable
{
    /**
     * The default date format for DateTime properties.
     */
    protected const DEFAULT_DATE_FORMAT = 'Y-m-d H:i:s';

    private const KIND_PASSTHROUGH = 0;

    private const KIND_INT = 1;

    private const KIND_FLOAT = 2;

    private const KIND_STRING = 3;

    private const KIND_BOOL = 4;

    private const KIND_ARRAY = 5;

    /**
     * Reflection parameters cache (class name => [ReflectionParameter]).
     */
    public static array $reflectionParametersCache = [];

    /**
     * Property map cache (class name => [snake_case key => camelCase property]).
     */
    public static array $propertyMapCache = [];

    /**
     * Reversed property map cache (class name => [camelCase key => snake_case property]).
     */
    public static ?array $reversedPropertyMapCache = [];

    /**
     * Flag to indicate if auto-casting is enabled.
     */
    protected static bool $autoCasting = true;

    /**
     * Cache for dependencies map (class name => dependencies array).
     */
    protected static array $dependenciesMapCache = [];

    /**
     * Construction recipe cache (class name => precompiled constructor descriptors).
     */
    private static array $constructionRecipeCache = [];

    /**
     * Serializer map cache (class name => [type => callable], or null when the hook is overridden).
     */
    private static array $serializerCache = [];

    /**
     * Whether empty dependency maps can be reused without skipping custom hooks.
     */
    private static array $emptyDependencyCacheEligibility = [];

    /**
     * The date format for DateTime properties.
     */
    protected static string $dateFormat = self::DEFAULT_DATE_FORMAT;

    /**
     * Cache for the array representation of the object.
     */
    protected array $arrayCache = [];

    /**
     * Create an instance of the class using the provided data array.
     */
    public static function make(array $data, bool $autoResolve = false): static
    {
        $cached = self::$constructionRecipeCache[static::class] ?? null;
        if (
            ! $autoResolve
            && $cached !== null
            && $cached['directReads']
            && $cached['properties'] === (static::$reversedPropertyMapCache[static::class] ?? null)
            && $cached['reflectionParameters'] === (static::$reflectionParametersCache[static::class] ?? null)
        ) {
            $recipe = $cached['recipe'];
        } else {
            $properties = static::getReversedPropertyMap();
            if ($autoResolve) {
                $data = static::getConvertedData($data);
            }

            $recipe = self::getConstructionRecipe($properties, static::getReflectionParameters());
        }

        $inlineCasts = $recipe['inlineCasts'];
        $inlineDefaults = $recipe['inlineDefaults'];
        $constructorArgs = [];

        foreach ($recipe['parameters'] as $entry) {
            $paramName = $entry['name'];
            $dataKey = $entry['dataKey'];

            // check if the data key exists in the array
            if ($dataKey !== null && isset($data[$dataKey])) {
                $dataValue = $data[$dataKey];
            } elseif ($dataKey !== null && array_key_exists($dataKey, $data)) {
                $dataValue = null;
            // use the default value if available
            } elseif ($entry['hasDefault']) {
                $constructorArgs[$paramName] = $entry['parameter']->getDefaultValue();
                continue;
            } else {
                $constructorArgs[$paramName] = $inlineDefaults
                    ? ($entry['nullOnMissing'] ? null : self::throwMissingProperty($paramName))
                    : static::getDefaultValueForType($entry['parameter']);

                continue;
            }

            // convert the value to the correct type automatically
            if (static::$autoCasting) {
                if (! $inlineCasts) {
                    $dataValue = static::convertValueToType($dataValue, $entry['parameter']);
                } elseif (! ($entry['allowsNull'] && $dataValue === null)) {
                    $dataValue = match ($entry['kind']) {
                        self::KIND_INT => (int) $dataValue,
                        self::KIND_FLOAT => (float) $dataValue,
                        self::KIND_STRING => (string) $dataValue,
                        self::KIND_BOOL => (bool) $dataValue,
                        self::KIND_ARRAY => is_array($dataValue) ? $dataValue : [$dataValue],
                        default => $dataValue,
                    };
                }
            }

            $constructorArgs[$paramName] = $dataValue;
        }

        return new static(...$constructorArgs);
    }

    /**
     * Create an instance of the class using the provided data array.
     * This is an alias of the `make` method.
     */
    public static function from(array $data, bool $autoResolve = false): static
    {
        return static::make($data, $autoResolve);
    }

    /**
     * Get the customized dependencies map.
     *
     * @return array<string, callable>
     */
    protected static function getCustomizedDependencies(): array
    {
        return [
            DateTimeInterface::class => $asDateTime = fn ($value) => $value ? static::asDateTime($value) : null,
            CarbonInterface::class => $asDateTime,
            DateTime::class => $asDateTime,
            Carbon::class => $asDateTime,
            BaseCarbon::class => $asDateTime,
        ];
    }

    /**
     * Get the serialization handlers for specific dependency types.
     *
     * @return array<string, callable>
     */
    protected static function getSerializers(): array
    {
        return [
            DateTimeInterface::class => $asIsoString = fn ($value) => $value->format('c'),
            DateTime::class => $asIsoString,
            CarbonInterface::class => $asIsoString,
            Carbon::class => $asIsoString,
            BaseCarbon::class => $asIsoString,
        ];
    }

    /**
     * Return a timestamp as DateTime object.
     */
    protected static function asDateTime(mixed $value): CarbonInterface
    {
        // If this value is already a Carbon instance, we shall just return it as is.
        // This prevents us having to re-instantiate a Carbon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTimeInterface) {
            return Carbon::parse(
                $value->format('Y-m-d H:i:s.u'),
                $value->getTimezone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value);
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if (static::isStandardDateFormat($value)) {
            return Carbon::instance(Carbon::createFromFormat('Y-m-d', $value)->startOfDay());
        }

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        if (Carbon::hasFormat($value, static::$dateFormat)) {
            return Carbon::createFromFormat(static::$dateFormat, $value) ?: Carbon::parse($value);
        }

        return Carbon::parse($value);
    }

    /**
     * Determine if the given value is a standard date format.
     */
    protected static function isStandardDateFormat(mixed $value): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', (string) $value);
    }

    /**
     * Get the converted data array with dependencies resolved.
     */
    protected static function getConvertedData(array $data): array
    {
        if (! $dependencies = static::getDependenciesData()) {
            return $data;
        }

        return static::replaceDependenciesData(
            $dependencies,
            $data
        );
    }

    /**
     * Get the dependencies map for the current class.
     *
     * @return array<string, array{handler: callable, children: array}>
     */
    protected static function getDependenciesData(): array
    {
        if ($dependencies = static::$dependenciesMapCache[static::class] ?? null) {
            return $dependencies;
        }

        if ($dependencies === [] && self::canCacheEmptyDependencies()) {
            return [];
        }

        return static::$dependenciesMapCache[static::class] = static::resolveDependenciesMap(static::class);
    }

    /**
     * Custom dependency hooks may start returning dependencies after configuration changes.
     */
    private static function canCacheEmptyDependencies(): bool
    {
        if (isset(self::$emptyDependencyCacheEligibility[static::class])) {
            return self::$emptyDependencyCacheEligibility[static::class];
        }

        foreach ([
            'resolveDependenciesMap',
            'getCustomizedDependencies',
            'getDependencyFromUnionType',
            'hasNullableUnionType',
            'isAutoCasting',
            'convertPropertyToDataKey',
        ] as $hook) {
            if (self::overridesHook($hook)) {
                return self::$emptyDependencyCacheEligibility[static::class] = false;
            }
        }

        return self::$emptyDependencyCacheEligibility[static::class] = true;
    }

    protected static function getDependencyFromUnionType(ReflectionUnionType $type): ?ReflectionNamedType
    {
        foreach ($type->getTypes() as $namedType) {
            if (! $namedType instanceof ReflectionNamedType) {
                continue;
            }

            $className = $namedType->getName();
            if (
                is_subclass_of($className, DataObject::class)
                || is_a($className, DateTimeInterface::class, true)
            ) {
                return $namedType;
            }
        }

        return null;
    }

    /**
     * Check if the union type allows null.
     */
    protected static function hasNullableUnionType(ReflectionUnionType $type): bool
    {
        foreach ($type->getTypes() as $namedType) {
            if ($namedType->allowsNull()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively resolve the dependencies map for the given class.
     *
     * @param array<string, bool> $visited
     * @return array<string, array{handler: callable, children: array}>
     */
    protected static function resolveDependenciesMap(string $class, array &$visited = []): array
    {
        if (isset($visited[$class])) {
            return [];
        }

        $visited[$class] = true;
        $reflection = new ReflectionClass($class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        $customizedDependencies = static::getCustomizedDependencies();

        $result = [];
        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $propertyType = $property->getType();

            if (! $propertyType instanceof ReflectionNamedType && ! $propertyType instanceof ReflectionUnionType) {
                continue;
            }

            $allowsNull = $propertyType->allowsNull();
            if ($propertyType instanceof ReflectionUnionType) {
                $allowsNull = static::hasNullableUnionType($propertyType);
                $propertyType = static::getDependencyFromUnionType($propertyType);

                if ($propertyType === null) {
                    continue;
                }
            }

            $typeName = $propertyType->getName();
            if (is_subclass_of($typeName, DataObject::class)) {
                $dataKey = $typeName::isAutoCasting()
                    ? $typeName::convertPropertyToDataKey($property->getName())
                    : $property->getName();
                $result[$dataKey] = [
                    'handler' => fn ($value) => $value instanceof $typeName ? $value : $typeName::make($value),
                    'nullable' => $allowsNull,
                    'children' => static::resolveDependenciesMap($typeName, $visited),
                ];
                continue;
            }
            $dataKey = static::isAutoCasting()
                ? static::convertPropertyToDataKey($property->getName())
                : $property->getName();
            if (enum_exists($typeName) && is_subclass_of($typeName, BackedEnum::class, true)) {
                $result[$dataKey] = [
                    'handler' => fn ($value) => $value instanceof $typeName ? $value : $typeName::from($value),
                    'nullable' => $allowsNull,
                    'children' => [],
                ];
                continue;
            }
            if ($resolver = $customizedDependencies[$typeName] ?? null) {
                $result[$dataKey] = [
                    'handler' => $resolver,
                    'nullable' => $allowsNull,
                    'children' => static::resolveDependenciesMap($typeName, $visited),
                ];
                continue;
            }
        }

        unset($visited[$class]);

        return $result;
    }

    /**
     * Recursively replace dependencies data in the given data array.
     */
    protected static function replaceDependenciesData(array $dependencies, array $data): ?array
    {
        foreach ($dependencies as $key => $dependency) {
            $handler = $dependency['handler'];
            $children = $dependency['children'] ?? [];
            $nullable = $dependency['nullable'] ?? false;
            $matched = $data[$key] ?? null;

            if ($nullable && $matched === null) {
                $data[$key] = null;
                continue;
            }
            if (! is_array($matched)) {
                $data[$key] = call_user_func_array(
                    $handler,
                    [$matched === null ? [] : $matched]
                );
                continue;
            }

            if ($children) {
                $data[$key] = static::replaceDependenciesData($children, $matched);
            }

            $data[$key] = call_user_func_array($handler, [$data[$key]]);
        }

        return $data;
    }

    /**
     * Enable or disable auto-casting of data values.
     *
     * Boot-only. The auto-casting flag persists in a static property for the
     * worker lifetime and affects every subsequent data object hydration.
     */
    public static function enableAutoCasting(): void
    {
        static::$autoCasting = true;
    }

    /**
     * Enable or disable auto-casting of data values.
     */
    public static function isAutoCasting(): bool
    {
        return static::$autoCasting;
    }

    /**
     * Disable auto-casting of data values.
     *
     * Boot-only. The auto-casting flag persists in a static property for the
     * worker lifetime and affects every subsequent data object hydration.
     */
    public static function disableAutoCasting(): void
    {
        static::$autoCasting = false;
    }

    /**
     * Convert the property name to the data key format.
     * It converts camelCase to snake_case by default.
     */
    public static function convertPropertyToDataKey(string $input): string
    {
        return Str::snake($input);
    }

    /**
     * Convert the data key to the property name format.
     * It converts snake_case to camelCase by default.
     */
    public static function convertDataKeyToProperty(string $input): string
    {
        return Str::camel($input);
    }

    /**
     * Get the precompiled construction recipe for the current class.
     *
     * @return array{
     *     parameters: list<array{
     *         name: string,
     *         dataKey: null|string,
     *         kind: int,
     *         allowsNull: bool,
     *         hasDefault: bool,
     *         nullOnMissing: bool,
     *         parameter: ReflectionParameter
     *     }>,
     *     inlineCasts: bool,
     *     inlineDefaults: bool
     * }
     */
    private static function getConstructionRecipe(array $properties, array $reflectionParameters): array
    {
        $cached = self::$constructionRecipeCache[static::class] ?? null;
        if ($cached !== null && $cached['properties'] === $properties && $cached['reflectionParameters'] === $reflectionParameters) {
            return $cached['recipe'];
        }

        $recipe = self::compileConstructionRecipe($properties, $reflectionParameters);
        self::$constructionRecipeCache[static::class] = [
            'directReads' => ! self::overridesHook('getReversedPropertyMap')
                && ! self::overridesHook('getPropertyMap')
                && ! self::overridesHook('getReflectionParameters'),
            'properties' => $properties,
            'reflectionParameters' => $reflectionParameters,
            'recipe' => $recipe,
        ];

        return $recipe;
    }

    /**
     * Compile the construction recipe for the current class.
     *
     * The recipe stores only declaration facts derived from reflection, so `make()`
     * never repeats per-parameter reflection calls. It never retains evaluated
     * default values, which would otherwise be shared between instances.
     *
     * The source maps are checked on each construction so changes to maps returned
     * by overridden hooks and invalidation of public reflection caches remain effective.
     */
    private static function compileConstructionRecipe(array $properties, array $reflectionParameters): array
    {
        $parameters = [];

        foreach ($reflectionParameters as $parameter) {
            $paramName = $parameter->getName();
            $type = $parameter->getType();
            $kind = self::KIND_PASSTHROUGH;

            if ($type instanceof ReflectionNamedType) {
                $kind = match ($type->getName()) {
                    'int' => self::KIND_INT,
                    'float' => self::KIND_FLOAT,
                    'string' => self::KIND_STRING,
                    'bool' => self::KIND_BOOL,
                    'array' => self::KIND_ARRAY,
                    default => self::KIND_PASSTHROUGH,
                };
            }

            $hasDefault = $parameter->isDefaultValueAvailable();
            $nullOnMissing = $type === null || $type->allowsNull();
            $dataKey = $properties[$paramName] ?? null;

            $parameters[] = [
                'name' => $paramName,
                'dataKey' => $dataKey,
                'kind' => $kind,
                'allowsNull' => $type !== null && $type->allowsNull(),
                'hasDefault' => $hasDefault,
                'nullOnMissing' => $nullOnMissing,
                'parameter' => $parameter,
            ];
        }

        return [
            'parameters' => $parameters,
            // Subclasses may override these hooks. When they do, keep dispatching
            // through them so their behavior is preserved.
            'inlineCasts' => ! self::overridesHook('convertValueToType'),
            'inlineDefaults' => ! self::overridesHook('getDefaultValueForType'),
        ];
    }

    /**
     * Resolve the serializer map for the current class.
     *
     * Only the base implementation is known to be stateless, so it is the only one
     * memoized. An overriding hook may capture runtime configuration when it is built,
     * so it keeps being invoked on every call to preserve that behavior.
     *
     * @return array<string, callable>
     */
    private static function resolveSerializers(): array
    {
        if (! array_key_exists(static::class, self::$serializerCache)) {
            self::$serializerCache[static::class] = self::overridesHook('getSerializers')
                ? null
                : static::getSerializers();
        }

        return self::$serializerCache[static::class] ?? static::getSerializers();
    }

    /**
     * Determine whether the current class overrides the given base hook.
     */
    private static function overridesHook(string $method): bool
    {
        return (new ReflectionMethod(static::class, $method))
            ->getDeclaringClass()
            ->getName() !== self::class;
    }

    /**
     * Throw the missing required property error for the given parameter name.
     */
    private static function throwMissingProperty(string $property): never
    {
        throw new RuntimeException(
            "Missing required property `{$property}` in `" . static::class . '`'
        );
    }

    /**
     * Get the reflection parameters for the constructor.
     *
     * @return ReflectionParameter[]
     */
    protected static function getReflectionParameters(): array
    {
        if (! is_null($parameters = static::$reflectionParametersCache[static::class] ?? null)) {
            return $parameters;
        }

        $reflection = new ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor ? $constructor->getParameters() : [];

        return static::$reflectionParametersCache[static::class] = $parameters;
    }

    /**
     * Convert the value to the correct type based on the parameter type.
     */
    protected static function convertValueToType(mixed $value, ReflectionParameter $parameter): mixed
    {
        if (! $type = $parameter->getType()) {
            return $value;
        }
        if ($type->allowsNull() && is_null($value)) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            return match ($type->getName()) {
                'int' => (int) $value,
                'float' => (float) $value,
                'string' => (string) $value,
                'bool' => (bool) $value,
                'array' => is_array($value) ? $value : [$value],
                default => $value,
            };
        }

        return $value;
    }

    /**
     * Get default value for the parameter type.
     */
    protected static function getDefaultValueForType(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        if (! $type || $type->allowsNull()) {
            return null;
        }

        throw new RuntimeException(
            "Missing required property `{$parameter->name}` in `" . static::class . '`'
        );
    }

    /**
     * Get property map (snake_case key => camelCase property).
     *
     * @return array<string, string>
     */
    protected static function getPropertyMap(): array
    {
        if (isset(static::$propertyMapCache[static::class])) {
            return static::$propertyMapCache[static::class];
        }

        $reflection = new ReflectionClass(static::class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        $map = [];

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $propName = $property->getName();
            $snakeKey = static::convertPropertyToDataKey($propName);
            $map[$snakeKey] = $propName;
        }

        return static::$propertyMapCache[static::class] = $map;
    }

    /**
     * Get reversed property map (camelCase key => snake_case property).
     *
     * @return array<string, string>
     */
    protected static function getReversedPropertyMap(): array
    {
        if (isset(static::$reversedPropertyMapCache[static::class])) {
            return static::$reversedPropertyMapCache[static::class];
        }

        return static::$reversedPropertyMapCache[static::class] = array_flip(
            static::getPropertyMap()
        );
    }

    /**
     * Update the object properties with the provided data array.
     */
    public function update(array $data): static
    {
        $properties = static::getPropertyMap();
        foreach ($data as $key => $value) {
            $this->{$properties[$key]} = $value;
        }

        $this->refresh();

        return $this;
    }

    /**
     * Check if the offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, static::getPropertyMap());
    }

    /**
     * Get the value at the specified offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (array_key_exists($offset, $this->toArray())) {
            return $this->toArray()[$offset];
        }

        throw new OutOfBoundsException("Undefined offset: {$offset}");
    }

    /**
     * Set the value at the specified offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Data object may not be mutated using array access.');
    }

    /**
     * Unset the value at the specified offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Data object may not be mutated using array access.');
    }

    /**
     * Convert the object to an array representation.
     */
    public function toArray(): array
    {
        if ($this->arrayCache) {
            return $this->arrayCache;
        }

        $result = [];
        $map = static::getPropertyMap();

        $serializers = self::resolveSerializers();
        foreach ($map as $snakeKey => $propName) {
            $value = $this->{$propName};
            // recursively convert nested objects to arrays
            if ($value instanceof self) {
                $value = $value->toArray();
            } elseif (is_object($value) && $serializer = $serializers[$value::class] ?? null) {
                $value = $serializer($value);
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                $value = $value->toArray();
            }
            $result[$snakeKey] = $value;
        }

        return $this->arrayCache = $result;
    }

    /**
     * JSON serialize the object.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Return a refreshed instance of the object with cleared cache.
     */
    public function refresh(): static
    {
        $this->arrayCache = [];

        return $this;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$reflectionParametersCache = [];
        static::$propertyMapCache = [];
        static::$reversedPropertyMapCache = [];
        static::$autoCasting = true;
        static::$dependenciesMapCache = [];
        static::$dateFormat = self::DEFAULT_DATE_FORMAT;
        self::$constructionRecipeCache = [];
        self::$serializerCache = [];
        self::$emptyDependencyCacheEligibility = [];
    }
}
