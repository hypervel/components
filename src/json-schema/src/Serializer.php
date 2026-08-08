<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema;

use InvalidArgumentException;
use RuntimeException;

class Serializer
{
    /**
     * The properties to ignore when serializing.
     *
     * @var array<int, string>
     */
    protected static array $ignore = ['required', 'nullable', 'hasDefault'];

    /**
     * Serialize the given property to an array.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public static function serialize(Types\Type $type): array
    {
        /** @var array<string, mixed> $attributes */
        $attributes = (fn () => get_object_vars($type))->call($type);

        if ($type instanceof Types\AnyOfType) {
            $attributes['anyOf'] = array_map(
                static fn (Types\Type $schema) => static::serialize($schema),
                $attributes['schemas'],
            );

            unset($attributes['schemas']);

            if (static::isNullable($type)) {
                $attributes['anyOf'][] = ['type' => 'null'];
            }

            if ($attributes['anyOf'] === []) {
                throw new InvalidArgumentException('A JSON Schema anyOf must contain at least one schema.');
            }

            return static::filterAttributes($attributes);
        }

        $attributes['type'] = match (get_class($type)) {
            Types\ArrayType::class => 'array',
            Types\BooleanType::class => 'boolean',
            Types\IntegerType::class => 'integer',
            Types\NumberType::class => 'number',
            Types\ObjectType::class => 'object',
            Types\StringType::class => 'string',
            Types\UnionType::class => $attributes['types'],
            default => throw new RuntimeException('Unsupported [' . get_class($type) . '] type.'),
        };

        unset($attributes['types']);

        $nullable = static::isNullable($type);

        if ($nullable) {
            $attributes['type'] = is_array($attributes['type'])
                ? [...$attributes['type'], 'null']
                : [$attributes['type'], 'null'];
        }

        if ($attributes['type'] === []) {
            throw new InvalidArgumentException('A JSON Schema union must contain at least one type.');
        }

        $attributes = static::filterAttributes($attributes);

        if ($type instanceof Types\ObjectType) {
            if (isset($attributes['default']) && is_array($attributes['default']) && array_is_list($attributes['default'])) {
                $attributes['default'] = (object) $attributes['default'];
            }

            if (count($attributes['properties']) === 0) {
                unset($attributes['properties']);
            } else {
                $required = array_map(
                    'strval',
                    array_keys(array_filter(
                        $attributes['properties'],
                        static fn (Types\Type $property) => static::isRequired($property),
                    ))
                );

                if ($required !== []) {
                    $attributes['required'] = $required;
                }

                $properties = array_map(
                    static fn (Types\Type $property) => static::serialize($property),
                    $attributes['properties'],
                );

                $attributes['properties'] = array_is_list($properties)
                    ? (object) $properties
                    : $properties;
            }
        }

        if ($type instanceof Types\ArrayType) {
            if (isset($attributes['items']) && $attributes['items'] instanceof Types\Type) {
                $attributes['items'] = static::serialize($attributes['items']);
            }
        }

        return $attributes;
    }

    /**
     * Remove internal and unset attributes before publication.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected static function filterAttributes(array $attributes): array
    {
        $hasDefault = $attributes['hasDefault'];

        return array_filter($attributes, static function (mixed $value, string $key) use ($hasDefault): bool {
            if (in_array($key, static::$ignore, true)) {
                return false;
            }

            return $value !== null || ($key === 'default' && $hasDefault);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Determine if the given type is required.
     */
    protected static function isRequired(Types\Type $type): bool
    {
        $attributes = (fn () => get_object_vars($type))->call($type);

        return isset($attributes['required']) && $attributes['required'] === true;
    }

    /**
     * Determine if the given type is nullable.
     */
    protected static function isNullable(Types\Type $type): bool
    {
        $attributes = (fn () => get_object_vars($type))->call($type);

        return isset($attributes['nullable']) && $attributes['nullable'] === true;
    }
}
