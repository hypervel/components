<?php

declare(strict_types=1);

namespace Hypervel\Ai\Schema;

use Generator;
use InvalidArgumentException;

class SchemaNormalizer
{
    /**
     * The maximum number of schema nodes normalized in one call.
     */
    private const int MAX_NODES = 20000;

    /**
     * Type-specific constraint keywords the deserializer rejects on a multi-type union.
     */
    private const array TYPE_KEYWORDS = [
        'minLength', 'maxLength', 'pattern', 'format',
        'minimum', 'maximum', 'multipleOf',
        'items', 'minItems', 'maxItems', 'uniqueItems',
        'properties', 'required', 'additionalProperties',
    ];

    /**
     * Keywords the deserializer cannot represent and that are dropped.
     */
    private const array UNSUPPORTED = [
        '$schema', '$id', '$anchor', '$comment', 'not', 'if', 'then', 'else',
        'patternProperties', 'dependentSchemas', 'dependentRequired', 'unevaluatedProperties',
        'contains', 'minContains', 'maxContains', 'prefixItems', 'examples', 'deprecated',
        'readOnly', 'writeOnly', 'minProperties', 'maxProperties', 'exclusiveMinimum', 'exclusiveMaximum',
        'propertyNames', 'unevaluatedItems', '$dynamicRef',
    ];

    /**
     * The JSON Schema type strings the deserializer understands.
     */
    private const array TYPES = ['string', 'integer', 'number', 'boolean', 'object', 'array', 'null'];

    /**
     * The number of nodes normalized so far.
     */
    private int $nodes = 0;

    /**
     * Normalize a tool input schema, projecting root object alternatives into a property map.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function normalize(array $schema): array
    {
        $normalizer = new self;

        return $normalizer->mergeUnion($normalizer->node($schema, $schema, []));
    }

    /**
     * Normalize a single schema node into the deserializable subset.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $root
     * @param array<string, true> $seen
     * @return array<string, mixed>
     */
    private function node(array $schema, array $root, array $seen): array
    {
        // Reference expansion happens before the deserializer can apply its own work limit.
        if (++$this->nodes > self::MAX_NODES) {
            throw new InvalidArgumentException('The JSON Schema is too large to normalize; it expands beyond [' . self::MAX_NODES . '] nodes.');
        }

        [$schema, $seen] = $this->inlineRefs($schema, $root, $seen);

        $schema = $this->mergeAllOf($schema, $root, $seen);

        // Keep each alternative's constraints; the MCP server still enforces oneOf exclusivity.
        if (isset($schema['oneOf']) && ! isset($schema['anyOf'])) {
            $schema['anyOf'] = $schema['oneOf'];
            unset($schema['oneOf']);
        }

        $schema = $this->preserveAnyOf($schema, $root, $seen);
        $schema = $this->collapseMultiType($schema);
        $schema = $this->normalizeKeywords($schema);
        $schema = $this->normalizeChildren($schema, $root, $seen);

        return $this->ensureType($schema);
    }

    /**
     * Inline local "$ref" pointers (cycle-safe); drop remote or unresolvable ones.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $root
     * @param array<string, true> $seen
     * @return array{0: array<string, mixed>, 1: array<string, true>}
     */
    private function inlineRefs(array $schema, array $root, array $seen): array
    {
        while (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ref = $schema['$ref'];

            unset($schema['$ref']);

            if (isset($seen[$ref]) || ($resolved = $this->lookupRef($ref, $root)) === null) {
                break;
            }

            $seen[$ref] = true;
            $schema = array_merge($resolved, $schema);
        }

        return [$schema, $seen];
    }

    /**
     * Resolve a local JSON pointer against the root schema.
     *
     * @param array<string, mixed> $root
     * @return null|array<string, mixed>
     */
    private function lookupRef(string $ref, array $root): ?array
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $target = $root;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return null;
            }

            $target = $target[$segment];
        }

        return is_array($target) ? $target : null;
    }

    /**
     * Flatten "allOf" branches into the node; the deserializer has no intersection type.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $root
     * @param array<string, true> $seen
     * @return array<string, mixed>
     */
    private function mergeAllOf(array $schema, array $root, array $seen): array
    {
        while (is_array($schema['allOf'] ?? null)) {
            $branches = $schema['allOf'];

            unset($schema['allOf']);

            $merged = [];

            foreach ($branches as $branch) {
                if (is_array($branch)) {
                    [$branch, $seen] = $this->inlineRefs($branch, $root, $seen);
                    $merged = $this->mergeSchema($merged, $branch);
                }
            }

            $schema = $this->mergeSchema($merged, $schema);
        }

        return $schema;
    }

    /**
     * Merge two schema fragments, unioning "required" and recursively merging "properties".
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    private function mergeSchema(array $base, array $overlay): array
    {
        $required = array_values(array_unique(array_merge(
            is_array($base['required'] ?? null) ? $base['required'] : [],
            is_array($overlay['required'] ?? null) ? $overlay['required'] : [],
        )));

        $properties = is_array($base['properties'] ?? null) ? $base['properties'] : [];

        foreach (is_array($overlay['properties'] ?? null) ? $overlay['properties'] : [] as $key => $value) {
            $properties[$key] = isset($properties[$key]) && is_array($properties[$key]) && is_array($value)
                ? $this->mergeSchema($properties[$key], $value)
                : $value;
        }

        $merged = array_merge($base, $overlay);

        if ($required !== []) {
            $merged['required'] = $required;
        }

        if ($properties !== []) {
            $merged['properties'] = $properties;
        }

        return $merged;
    }

    /**
     * Keep anyOf compositions intact.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $root
     * @param array<string, true> $seen
     * @return array<string, mixed>
     */
    private function preserveAnyOf(array $schema, array $root, array $seen): array
    {
        if (! is_array($schema['anyOf'] ?? null)) {
            return $schema;
        }

        $carry = ['anyOf' => true, 'title' => true, 'description' => true, 'enum' => true, 'default' => true];
        $base = array_diff_key($schema, $carry);

        $branches = [];
        $resolved = false;

        foreach ($schema['anyOf'] as $branch) {
            if (! is_array($branch)) {
                continue;
            }
            if ($branch === []) {
                continue;
            }
            [$branch, $branchSeen] = $this->inlineRefs($branch, $root, $seen);

            if ($branch === []) {
                continue;
            }

            if ($this->isNullBranch($branch)) {
                $branches[] = ['type' => 'null'];

                continue;
            }

            $branches[] = $this->node($base === [] ? $branch : $this->mergeSchema($base, $branch), $root, $branchSeen);
            $resolved = true;
        }

        if (! $resolved) {
            unset($schema['anyOf']);

            return $schema;
        }

        $schema = array_intersect_key($schema, $carry);
        $schema['anyOf'] = $branches;

        return $schema;
    }

    /**
     * Project normalized root object alternatives into the property map expected by Tool::schema().
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function mergeUnion(array $schema): array
    {
        if (! isset($schema['anyOf'])) {
            return $schema;
        }

        $nullable = false;
        $objects = [];

        foreach ($this->rootBranches($schema) as $branch) {
            if ($this->isNullBranch($branch)) {
                $nullable = true;
            } elseif ($this->isObjectBranch($branch)) {
                $objects[] = $branch;
            }
        }

        if ($objects === []) {
            return $schema;
        }

        unset($schema['anyOf']);

        $schema = count($objects) === 1
            ? $this->mergeSchema($objects[0], $schema)
            : $this->reduceObjectGroup($objects, $schema);

        if (! $nullable) {
            return $schema;
        }

        $schema['type'] = ['object', 'null'];

        return $schema;
    }

    /**
     * Yield root alternatives without flattening compositions inside their properties.
     *
     * @param array<string, mixed> $schema
     * @return Generator<int, array<string, mixed>>
     */
    private function rootBranches(array $schema): Generator
    {
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $branch) {
                yield from $this->rootBranches($branch);
            }
        } else {
            yield $schema;
        }
    }

    /**
     * Merge root object variants, retaining their common requirements and outer annotations.
     *
     * @param array<int, array<string, mixed>> $variants
     * @param array<string, mixed> $outer
     * @return array<string, mixed>
     */
    private function reduceObjectGroup(array $variants, array $outer): array
    {
        $propertyLists = [];
        $requiredSets = [];
        $nullable = false;
        $description = null;
        $closed = true;

        foreach ($variants as $variant) {
            $nullable = $nullable || is_array($variant['type'] ?? null);

            foreach (is_array($variant['properties'] ?? null) ? $variant['properties'] : [] as $key => $prop) {
                $propertyLists[$key][] = $prop;
            }

            $requiredSets[] = $variant['required'] ?? [];

            if ($description === null && is_string($variant['description'] ?? null)) {
                $description = $variant['description'];
            }

            $closed = $closed && (($variant['additionalProperties'] ?? null) === false);
        }

        $properties = [];

        foreach ($propertyLists as $key => $list) {
            $properties[$key] = $this->mergeVariantValues($list);
        }

        $result = $outer;
        $result['type'] = $nullable ? ['object', 'null'] : 'object';

        if ($properties !== []) {
            $result['properties'] = $properties;
        }

        $required = array_values(array_intersect(...$requiredSets));

        if ($required !== []) {
            $result['required'] = $required;
        }

        if ($description !== null && ! isset($result['description'])) {
            $result['description'] = $description;
        }

        if ($closed) {
            $result['additionalProperties'] = false;
        }

        return $result;
    }

    /**
     * Preserve the distinct schemas a property takes across root alternatives.
     *
     * @param array<int, mixed> $list
     * @return array<string, mixed>
     */
    private function mergeVariantValues(array $list): array
    {
        $unique = [];

        foreach ($list as $value) {
            if (! in_array($value, $unique, true)) {
                $unique[] = $value;
            }
        }

        return count($unique) === 1 ? $unique[0] : ['anyOf' => $unique];
    }

    /**
     * Determine whether a normalized branch's type is a plain object, optionally nullable.
     *
     * @param array<string, mixed> $branch
     */
    private function isObjectBranch(array $branch): bool
    {
        $type = $branch['type'] ?? null;
        $nonNull = array_values(array_filter(is_array($type) ? $type : [$type], fn (mixed $value): bool => $value !== 'null'));

        return $nonNull === ['object'];
    }

    /**
     * Determine whether the branch only represents null.
     *
     * @param array<string, mixed> $schema
     */
    private function isNullBranch(array $schema): bool
    {
        return in_array($schema['type'] ?? null, ['null', ['null']], true);
    }

    /**
     * Keep multi-type unions deserializable and drop unrepresentable "null"-only types.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function collapseMultiType(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (! is_array($type)) {
            if ($type === 'null' || ($type !== null && ! in_array($type, self::TYPES, true))) {
                unset($schema['type']);
            }

            return $schema;
        }

        $valid = array_values(array_filter($type, fn (mixed $value): bool => in_array($value, self::TYPES, true)));
        $nonNull = array_values(array_filter($valid, fn (string $value): bool => $value !== 'null'));

        if ($nonNull === []) {
            unset($schema['type']);

            return $schema;
        }

        $schema['type'] = count($valid) === 1 ? $valid[0] : $valid;

        if (count($nonNull) > 1) {
            foreach (self::TYPE_KEYWORDS as $keyword) {
                unset($schema[$keyword]);
            }
        }

        return $schema;
    }

    /**
     * Drop unsupported keywords, rewrite const to enum, and normalize additionalProperties.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeKeywords(array $schema): array
    {
        foreach (self::UNSUPPORTED as $keyword) {
            unset($schema[$keyword]);
        }

        unset($schema['$defs'], $schema['definitions']);

        if (array_key_exists('const', $schema)) {
            $schema['enum'] = [$schema['const']];
            unset($schema['const']);
        }

        if (! isset($schema['type']) && array_key_exists('additionalProperties', $schema)) {
            $schema['type'] = 'object';
        }

        if (($schema['additionalProperties'] ?? false) !== false) {
            unset($schema['additionalProperties']);
        }

        return $schema;
    }

    /**
     * Recurse into "properties" (pruning orphaned "required") and "items".
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $root
     * @param array<string, true> $seen
     * @return array<string, mixed>
     */
    private function normalizeChildren(array $schema, array $root, array $seen): array
    {
        $properties = [];

        if (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $key => $definition) {
                if (is_array($definition)) {
                    $properties[$key] = $this->node($definition, $root, $seen);
                }
            }

            $schema['properties'] = $properties;
        }

        if (is_array($schema['required'] ?? null)) {
            $schema['required'] = array_values(array_filter(
                $schema['required'],
                fn (mixed $name): bool => is_string($name) && array_key_exists($name, $properties),
            ));
        }

        if (isset($schema['items'])) {
            $schema['items'] = is_array($schema['items']) && ! array_is_list($schema['items'])
                ? $this->node($schema['items'], $root, $seen)
                : null;

            if ($schema['items'] === null) {
                unset($schema['items']);
            }
        }

        return $schema;
    }

    /**
     * Give a node an explicit type so a typeless node never reaches the deserializer.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function ensureType(array $schema): array
    {
        if (isset($schema['anyOf']) || isset($schema['oneOf']) || isset($schema['allOf'])) {
            return $schema;
        }

        $type = $schema['type'] ?? null;
        $nonNull = array_filter(is_array($type) ? $type : [$type], fn (mixed $value): bool => $value !== 'null');

        if ($nonNull !== [] && array_filter($nonNull, fn (mixed $value): bool => ! in_array($value, self::TYPES, true)) === []) {
            return $schema;
        }

        unset($schema['type']);
        $schema['type'] = $this->baseType($schema);

        return $schema;
    }

    /**
     * Infer a single base type from a node's shape, defaulting to "string".
     *
     * @param array<string, mixed> $schema
     */
    private function baseType(array $schema): string
    {
        return match (true) {
            isset($schema['properties']), isset($schema['required']), isset($schema['additionalProperties']) => 'object',
            isset($schema['items']), isset($schema['minItems']), isset($schema['maxItems']), isset($schema['uniqueItems']) => 'array',
            isset($schema['enum']) && is_array($schema['enum']) => $this->inferEnumType($schema['enum']),
            isset($schema['minimum']), isset($schema['maximum']), isset($schema['multipleOf']) => 'number',
            default => 'string',
        };
    }

    /**
     * Infer the scalar type shared by an enum, defaulting to "string".
     *
     * @param array<int, mixed> $enum
     */
    private function inferEnumType(array $enum): string
    {
        $resolved = null;

        foreach ($enum as $value) {
            $current = match (true) {
                is_bool($value) => 'boolean',
                is_int($value) => 'integer',
                is_float($value) => 'number',
                is_string($value) => 'string',
                default => null,
            };

            if ($current === null) {
                return 'string';
            }

            if ($resolved === null || $resolved === $current) {
                $resolved = $current;

                continue;
            }

            if (in_array($resolved, ['integer', 'number'], true) && in_array($current, ['integer', 'number'], true)) {
                $resolved = 'number';

                continue;
            }

            return 'string';
        }

        return $resolved ?? 'string';
    }
}
