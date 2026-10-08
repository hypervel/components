<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Closure;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Container\Container;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Pipeline\Pipeline;
use Hypervel\Support\Str;

/**
 * Get an ad-hoc agent instance.
 */
function agent(
    string $instructions = '',
    iterable $messages = [],
    iterable $tools = [],
    ?Closure $schema = null,
): Agent {
    return $schema
        ? new StructuredAnonymousAgent($instructions, $messages, $tools, $schema)
        : new AnonymousAgent($instructions, $messages, $tools);
}

/**
 * Get a new pipeline instance.
 */
function pipeline(): Pipeline
{
    return new Pipeline(Container::getInstance());
}

/**
 * Generate a new ULID.
 */
function ulid(): string
{
    return strtolower((string) Str::ulid());
}

/**
 * Generate fake data from a JSON schema type.
 */
function generate_fake_data_for_json_schema_type(Type $type): mixed
{
    return generate_fake_data_for_json_schema($type->toArray());
}

/**
 * Generate fake data from a serialized schema without serializing its children again.
 *
 * @param array<string, mixed> $attributes
 *
 * @internal
 */
function generate_fake_data_for_json_schema(array $attributes): mixed
{
    $type = $attributes['type'] ?? null;
    $type = is_array($type) ? $type[0] : $type;

    if (isset($attributes['enum']) && is_array($attributes['enum']) && count($attributes['enum']) > 0) {
        $enumValue = $attributes['enum'][array_rand($attributes['enum'])];

        return $type === 'array'
            ? [$enumValue]
            : $enumValue;
    }

    if (array_key_exists('default', $attributes)) {
        return $type === 'object' && is_object($attributes['default'])
            ? (array) $attributes['default']
            : $attributes['default'];
    }

    if (isset($attributes['anyOf'])) {
        return generate_fake_data_for_json_schema($attributes['anyOf'][0]);
    }

    return match ($type) {
        'object' => (function () use ($attributes): array {
            $result = [];

            foreach ($attributes['properties'] ?? [] as $key => $property) {
                $result[$key] = generate_fake_data_for_json_schema($property);
            }

            return $result;
        })(),

        'array' => (function () use ($attributes): array {
            $min = $attributes['minItems'] ?? min(1, $attributes['maxItems'] ?? 1);
            $max = $attributes['maxItems'] ?? max($min, 3);

            $count = random_int($min, $max);

            if (! isset($attributes['items'])) {
                return [];
            }

            $result = [];

            for ($i = 0; $i < $count; ++$i) {
                $result[] = generate_fake_data_for_json_schema(
                    $attributes['items']
                );
            }

            return $result;
        })(),

        'string' => (function () use ($attributes): string {
            if (isset($attributes['format'])) {
                return match ($attributes['format']) {
                    'date' => date('Y-m-d'),
                    'date-time' => date('c'),
                    'email' => 'user@example.com',
                    'time' => date('H:i:s'),
                    'uri', 'url' => 'https://example.com',
                    'uuid' => (string) Str::uuid(),
                    default => 'string',
                };
            }

            $min = $attributes['minLength'] ?? min(1, $attributes['maxLength'] ?? 1);
            $max = $attributes['maxLength'] ?? max($min, 10);

            return Str::random(random_int($min, $max));
        })(),

        'integer' => (function () use ($attributes): int {
            $min = $attributes['minimum'] ?? min(0, $attributes['maximum'] ?? 0);
            $max = $attributes['maximum'] ?? max($min, 100);

            return random_int($min, $max);
        })(),

        'number' => (function () use ($attributes): float {
            $min = $attributes['minimum'] ?? min(0.0, $attributes['maximum'] ?? 0.0);
            $max = $attributes['maximum'] ?? max($min, 100.0);

            return $min + mt_rand() / mt_getrandmax() * ($max - $min);
        })(),

        'boolean' => random_int(0, 1) === 0,

        default => null,
    };
}
