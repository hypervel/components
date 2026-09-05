<?php

declare(strict_types=1);

namespace Hypervel\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Http\CastsRequestInput;
use Hypervel\Contracts\Http\RequestCastable;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Http\DataObjectRequestCast;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Stringable as BaseStringable;

/**
 * @phpstan-type Recipe array{
 *     name: string,
 *     kind: int,
 *     target: null|class-string,
 *     allowsNull: bool,
 *     hasDefault: bool
 * }
 *
 * @implements Arrayable<string, mixed>
 */
abstract class DataObject implements Arrayable, Jsonable, JsonSerializable, RequestCastable, Transient
{
    private const int KIND_PASSTHROUGH = 0;

    private const int KIND_ARRAY = 1;

    private const int KIND_BOOLEAN = 2;

    private const int KIND_FLOAT = 3;

    private const int KIND_INTEGER = 4;

    private const int KIND_STRING = 5;

    private const int KIND_ENUM = 6;

    private const int KIND_DATA_OBJECT = 7;

    private const int KIND_DATE = 8;

    /**
     * The compiled construction recipes.
     *
     * @var array<class-string, list<Recipe>>
     */
    private static array $recipes = [];

    /**
     * Create a new data object from the given values.
     */
    public static function from(array $data): static
    {
        $class = static::class;
        $arguments = [];

        foreach (self::recipe($class) as $property) {
            $name = $property['name'];

            if (isset($data[$name])) {
                $arguments[$name] = self::convert($data[$name], $property, $class);

                continue;
            }

            if (array_key_exists($name, $data)) {
                $arguments[$name] = null;

                continue;
            }

            if ($property['hasDefault']) {
                continue;
            }

            if ($property['allowsNull']) {
                $arguments[$name] = null;

                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Cannot create %s: required property [%s] is missing.',
                $class,
                $name,
            ));
        }

        return new static(...$arguments);
    }

    /**
     * Get the caster to use for validated request input.
     *
     * @param string[] $arguments
     */
    public static function castRequestUsing(array $arguments): CastsRequestInput
    {
        if ($arguments !== []) {
            throw new InvalidArgumentException(
                'Data object request cast [' . static::class . '] does not accept arguments.',
            );
        }

        return new DataObjectRequestCast(static::class);
    }

    /**
     * Convert the data object to an array.
     */
    public function toArray(): array
    {
        $values = (array) $this;
        $result = [];

        foreach (self::recipe(static::class) as $property) {
            $value = $values[$property['name']];
            $result[$property['name']] = ! is_array($value) && ! is_object($value)
                ? $value
                : self::normalize($value);
        }

        return $result;
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the data object to JSON.
     *
     * @throws JsonException
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Get the compiled construction recipe for the given class.
     *
     * @param class-string $class
     * @return list<Recipe>
     */
    private static function recipe(string $class): array
    {
        return self::$recipes[$class] ??= self::compileRecipe($class);
    }

    /**
     * Compile the construction recipe for the given class.
     *
     * @param class-string $class
     * @return list<Recipe>
     */
    private static function compileRecipe(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $parameters = $reflection->getConstructor()?->getParameters() ?? [];
        $recipe = [];
        $promoted = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (! $parameter->isPromoted()
                || ! $parameter->getDeclaringClass()?->getProperty($name)->isPublic()) {
                throw new LogicException(sprintf(
                    '%s constructor parameter [%s] must be a public promoted property.',
                    $class,
                    $name,
                ));
            }

            $kind = self::KIND_PASSTHROUGH;
            $target = null;
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                $kind = match ($type->getName()) {
                    'array' => self::KIND_ARRAY,
                    'bool' => self::KIND_BOOLEAN,
                    'float' => self::KIND_FLOAT,
                    'int' => self::KIND_INTEGER,
                    'string' => self::KIND_STRING,
                    default => self::KIND_PASSTHROUGH,
                };
            } elseif (($namedTarget = Reflector::getParameterClassName($parameter)) !== null) {
                if (enum_exists($namedTarget) && is_a($namedTarget, BackedEnum::class, true)) {
                    $kind = self::KIND_ENUM;
                    $target = $namedTarget;
                } elseif (is_a($namedTarget, self::class, true)) {
                    $kind = self::KIND_DATA_OBJECT;
                    $target = $namedTarget;
                } elseif (is_a($namedTarget, DateTimeInterface::class, true)) {
                    $kind = self::KIND_DATE;
                    $target = $namedTarget;
                }
            }

            $recipe[] = [
                'name' => $name,
                'kind' => $kind,
                'target' => $target,
                'allowsNull' => $parameter->allowsNull(),
                'hasDefault' => $parameter->isDefaultValueAvailable(),
            ];
            $promoted[$name] = true;
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic() && ! isset($promoted[$property->getName()])) {
                throw new LogicException(sprintf(
                    '%s public property [%s] must be promoted by its constructor.',
                    $class,
                    $property->getName(),
                ));
            }
        }

        return $recipe;
    }

    /**
     * Convert a supplied value according to its compiled property kind.
     *
     * @param Recipe $property
     * @param class-string $class
     */
    private static function convert(mixed $value, array $property, string $class): mixed
    {
        /** @var class-string $target */
        $target = $property['target'];

        return match ($property['kind']) {
            self::KIND_ARRAY => is_array($value)
                ? $value
                : self::throwInvalidValue($class, $property['name'], 'array', $value),
            self::KIND_BOOLEAN => self::convertBoolean($value)
                ?? self::throwInvalidValue($class, $property['name'], 'bool', $value),
            self::KIND_FLOAT => self::convertFloat($value)
                ?? self::throwInvalidValue($class, $property['name'], 'float', $value),
            self::KIND_INTEGER => self::convertInteger($value)
                ?? self::throwInvalidValue($class, $property['name'], 'int', $value),
            self::KIND_STRING => self::convertString($value)
                ?? self::throwInvalidValue($class, $property['name'], 'string', $value),
            self::KIND_ENUM => $value instanceof $target ? $value : enum_from($target, $value),
            self::KIND_DATA_OBJECT => is_array($value) ? $target::from($value) : $value,
            self::KIND_DATE => self::convertDate($value, $target),
            default => $value,
        };
    }

    /**
     * Convert the given value to a boolean.
     */
    private static function convertBoolean(mixed $value): ?bool
    {
        return is_bool($value)
            ? $value
            : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Convert the given value to a float.
     */
    private static function convertFloat(mixed $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return is_string($value)
            ? filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE)
            : null;
    }

    /**
     * Convert the given value to an integer.
     */
    private static function convertInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) || is_float($value)
            ? filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)
            : null;
    }

    /**
     * Convert the given value to a string.
     */
    private static function convertString(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_scalar($value), $value instanceof BaseStringable => (string) $value,
            default => null,
        };
    }

    /**
     * Convert the given value to a date.
     *
     * @param class-string $target
     */
    private static function convertDate(mixed $value, string $target): mixed
    {
        if ($value instanceof $target) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            $date = $value;
        } elseif (is_int($value) || is_float($value)) {
            $date = Date::createFromTimestamp($value, date_default_timezone_get());
        } elseif (is_string($value)) {
            $date = Date::parse($value);
        } else {
            return $value;
        }

        if ($target === DateTimeInterface::class || $target === CarbonInterface::class) {
            return $value instanceof DateTimeInterface
                ? Date::instance($value)
                : $date;
        }

        if (is_a($target, CarbonInterface::class, true)) {
            return $target::instance($date);
        }

        return $target::createFromInterface($date);
    }

    /**
     * Throw an exception for a value that cannot be converted.
     *
     * @param class-string $class
     */
    private static function throwInvalidValue(
        string $class,
        string $property,
        string $expected,
        mixed $value,
    ): never {
        $supplied = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        throw new InvalidArgumentException(sprintf(
            'Cannot create %s: property [%s] expects %s; received %s.',
            $class,
            $property,
            $expected,
            $supplied,
        ));
    }

    /**
     * Normalize a value for array and JSON output.
     */
    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value) && ! is_object($value)) {
            return $value;
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item);
            }

            return $normalized;
        }

        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof self => $value->toArray(),
            $value instanceof Arrayable => self::normalize($value->toArray()),
            default => $value,
        };
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$recipes = [];
    }
}
