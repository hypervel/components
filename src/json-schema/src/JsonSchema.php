<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema;

use Closure;
use Hypervel\JsonSchema\Types\Type;

/**
 * @method static Types\ObjectType object(Closure|array<string, Types\Type> $properties = [])
 * @method static Types\IntegerType integer()
 * @method static Types\NumberType number()
 * @method static Types\StringType string()
 * @method static Types\BooleanType boolean()
 * @method static Types\ArrayType array()
 */
class JsonSchema
{
    /**
     * Dynamically pass static methods to the schema instance.
     */
    public static function __callStatic(string $name, array $arguments): Type
    {
        return (new JsonSchemaTypeFactory())->{$name}(...$arguments);
    }
}
