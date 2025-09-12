<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ArrayAccess;
use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonInterface;
use DateTime;
use DateTimeInterface;
use JsonSerializable;
use LogicException;
use OutOfBoundsException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;

abstract class DataObject implements ArrayAccess, JsonSerializable
{
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
     * The date format for DateTime properties.
     */
    protected static string $dateFormat = 'Y-m-d H:i:s';

    /**
     * Cache for the array representation of the object.
     */
    protected array $arrayCache = [];

    /**
     * Create an instance of the class using the provided data array.
     */
    public static function make(array $data, bool $autoResolve = false): static
    {
        $properties = static::getReversedPropertyMap();
        if ($autoResolve) {
            $data = static::getConvertedData($data);
        }

        $constructorArgs = [];
        foreach (static::getReflectionParameters() as $parameter) {
            $paramName = $parameter->getName();
            $dataKey = $properties[$paramName];
            $dataValue = null;

            // check if the data key exists in the array
            // and convert the value to the correct type automatically
            if (array_key_exists($dataKey, $data)) {
                $dataValue = $data[$dataKey];
                if (static::$autoCasting) {
                    $dataValue = static::convertValueToType($dataValue, $parameter);
                }
            // use the default value if available
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dataValue = $parameter->getDefaultValue();
            } else {
                $dataValue = static::getDefaultValueForType($parameter);
            }

            $constructorArgs[$paramName] = $dataValue;
        }

        return new static(...$constructorArgs);
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
            return Carbon::createFromFormat(static::$dateFormat, $value);
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

        return static::$dependenciesMapCache[static::class] = static::resolveDependenciesMap(static::class);
    }

    protected static function getDependencyFromUnionType(ReflectionUnionType $type): ReflectionNamedType
    {
        foreach ($type->getTypes() as $namedType) {
            $className = $namedType->getName();
            if (is_subclass_of($className, DataObject::class)
                || is_subclass_of($className, DateTimeInterface::class)) {
                return $namedType;
            }
        }

        throw new RuntimeException('No valid dependency found in union type.');
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
            $allowsNull = $propertyType->allowsNull();
            if ($propertyType instanceof ReflectionUnionType) {
                $allowsNull = static::hasNullableUnionType($propertyType);
                $propertyType = static::getDependencyFromUnionType($propertyType);
            }
            /** @var ReflectionNamedType $propertyType */
            $typeName = $propertyType->getName();
            if (is_subclass_of($typeName, DataObject::class)) {
                $dataKey = $typeName::isAutoCasting()
                    ? $typeName::convertPropertyToDataKey($property->getName())
                    : $property->getName();
                $result[$dataKey] = [
                    'handler' => [$typeName, 'make'],
                    'nullable' => $allowsNull,
                    'children' => static::resolveDependenciesMap($typeName, $visited),
                ];
                continue;
            }
            if ($resolver = $customizedDependencies[$typeName] ?? null) {
                $dataKey = static::isAutoCasting()
                    ? static::convertPropertyToDataKey($property->getName())
                    : $property->getName();
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

            if ($nullable && is_null($matched)) {
                $data[$key] = null;
                continue;
            }
            if (! is_array($matched)) {
                $data[$key] = call_user_func_array(
                    $handler,
                    [is_null($matched) ? [] : $matched]
                );
                continue;
            }

            if ($children && ! is_null($matched)) {
                $data[$key] = static::replaceDependenciesData($children, $matched);
            }

            $data[$key] = call_user_func_array($handler, [$data[$key]]);
        }

        return $data;
    }

    /**
     * Enable or disable auto-casting of data values.
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
        if (! is_null($cache = static::$propertyMapCache[static::class] ?? null)) {
            return $cache;
        }

        $reflection = new ReflectionClass(static::class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $propName = $property->getName();
            $snakeKey = static::convertPropertyToDataKey($propName);
            static::$propertyMapCache[static::class][$snakeKey] = $propName;
        }

        return static::$propertyMapCache[static::class];
    }

    /**
     * Get reversed property map (camelCase key => snake_case property).
     *
     * @return array<string, string>
     */
    protected static function getReversedPropertyMap(): array
    {
        if (! is_null($cache = static::$reversedPropertyMapCache[static::class] ?? null)) {
            return $cache;
        }

        return static::$reversedPropertyMapCache[static::class] = array_flip(
            static::getPropertyMap()
        );
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
        return $this->toArray()[$offset]
            ?? throw new OutOfBoundsException("Undefined offset: {$offset}");
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

        foreach ($map as $snakeKey => $propName) {
            $value = $this->{$propName};
            // recursively convert nested objects to arrays
            if ($value instanceof self) {
                $value = $value->toArray();
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
}
