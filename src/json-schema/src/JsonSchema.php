<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema;

use Closure;
use Hypervel\JsonSchema\Types\AnyOfType;
use Hypervel\JsonSchema\Types\ArrayType;
use Hypervel\JsonSchema\Types\BooleanType;
use Hypervel\JsonSchema\Types\IntegerType;
use Hypervel\JsonSchema\Types\NumberType;
use Hypervel\JsonSchema\Types\ObjectType;
use Hypervel\JsonSchema\Types\StringType;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\JsonSchema\Types\UnionType;
use InvalidArgumentException;

/**
 * @method static ObjectType object(Closure|array<int|string, Type> $properties = [])
 * @method static AnyOfType anyOf(Closure|array<int, Type> $schemas)
 * @method static IntegerType integer()
 * @method static NumberType number()
 * @method static StringType string()
 * @method static BooleanType boolean()
 * @method static ArrayType array()
 * @method static UnionType union(array<int, string> $types)
 */
class JsonSchema
{
    /**
     * The maximum number of schema fragments that may be expanded when deserializing or building rules.
     */
    public const int MAX_NODES = 20000;

    /**
     * Build a type from a raw array of the Hypervel-supported JSON Schema subset.
     *
     * @param array<string, mixed> $schema
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $schema): Type
    {
        return Deserializer::deserialize($schema);
    }

    /**
     * Get the Hypervel validation rules for the properties of the given object schema.
     *
     * @param array<string, mixed>|Type $schema
     * @return array<string, array<int, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public static function rules(Type|array $schema): array
    {
        return ValidationRules::for($schema);
    }

    /**
     * Dynamically pass static methods to the schema instance.
     */
    public static function __callStatic(string $name, array $arguments): Type
    {
        return (new JsonSchemaTypeFactory)->{$name}(...$arguments);
    }
}
