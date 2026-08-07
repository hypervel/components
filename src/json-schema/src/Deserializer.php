<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema;

use InvalidArgumentException;
use stdClass;

class Deserializer
{
    /**
     * The type-specific keywords supported by concrete schema types.
     *
     * @var array<int, string>
     */
    protected const TYPE_SPECIFIC_KEYWORDS = [
        'minLength', 'maxLength', 'pattern', 'format',
        'minimum', 'maximum', 'multipleOf',
        'items', 'minItems', 'maxItems', 'uniqueItems',
        'properties', 'required', 'additionalProperties',
    ];

    /**
     * The JSON Schema 2020-12 assertions this builder cannot represent.
     *
     * @var array<int, string>
     */
    protected const UNSUPPORTED_ASSERTION_KEYWORDS = [
        'const', 'not', 'allOf', 'if', 'dependentSchemas', 'dependentRequired',
        'prefixItems', 'contains', 'patternProperties', 'propertyNames',
        'unevaluatedItems', 'unevaluatedProperties',
        'exclusiveMinimum', 'exclusiveMaximum', 'minProperties', 'maxProperties',
        '$dynamicRef',
    ];

    /**
     * The maximum number of schema fragments that may be expanded.
     */
    protected const MAX_NODES = 20000;

    /**
     * The maximum number of distinct references on one active path.
     */
    protected const MAX_REFERENCE_DEPTH = 256;

    /**
     * The number of schema fragments expanded so far.
     */
    protected int $nodes = 0;

    /**
     * The cache of resolved local "$ref" targets, keyed by reference.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $refCache = [];

    /**
     * Create a new deserializer instance.
     *
     * @param array<string, mixed> $root
     */
    protected function __construct(protected array $root)
    {
    }

    /**
     * Deserialize the supported JSON Schema subset into a type.
     *
     * @param array<string, mixed> $schema
     *
     * @throws InvalidArgumentException
     */
    public static function deserialize(array $schema): Types\Type
    {
        return (new static($schema))->build($schema);
    }

    /**
     * Build a type from the given schema fragment.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $refs
     *
     * @throws InvalidArgumentException
     */
    protected function build(array $schema, array $refs = []): Types\Type
    {
        $this->countNode();

        [$schema, $refs] = $this->resolveRef($schema, $refs);

        $this->ensureAssertionsAreSupported($schema);

        if (($type = $this->buildAnyOfComposition($schema, $refs)) !== null) {
            $this->applyCommon($type, $schema);

            return $type;
        }

        [$schema, $nullableFromUnion, $refs] = $this->normalizeUnions($schema, $refs);

        if ($nullableFromUnion) {
            $this->ensureAssertionsAreSupported($schema);
        }

        [$name, $nullableFromType] = $this->resolveType($schema);

        if (is_array($name)) {
            $this->ensureUnionConstraintsAreSupported($schema);

            $type = new Types\UnionType($name);
        } else {
            $type = match ($name) {
                'object' => $this->buildObject($schema, $refs),
                'array' => $this->buildArray($schema, $refs),
                'string' => $this->buildString($schema),
                'integer' => $this->buildInteger($schema),
                'number' => $this->buildNumber($schema),
                'boolean' => new Types\BooleanType,
                default => throw new InvalidArgumentException("Unsupported JSON Schema type [{$name}]."),
            };
        }

        $this->applyCommon($type, $schema);

        if ($nullableFromUnion || $nullableFromType) {
            $type->nullable();
        }

        return $type;
    }

    /**
     * Build an anyOf composition unless it is the existing nullable single-schema form.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $refs
     *
     * @throws InvalidArgumentException
     */
    protected function buildAnyOfComposition(array $schema, array $refs = []): ?Types\AnyOfType
    {
        if (! array_key_exists('anyOf', $schema)) {
            return null;
        }

        if (! is_array($schema['anyOf']) || $schema['anyOf'] === []) {
            throw new InvalidArgumentException('The JSON Schema [anyOf] keyword must be a non-empty array.');
        }

        $nullable = false;
        $branches = [];

        foreach ($schema['anyOf'] as $branch) {
            if (! is_array($branch)) {
                throw new InvalidArgumentException('Unable to represent the schema for an anyOf branch; boolean schemas are not supported.');
            }

            [$branch, $branchRefs] = $this->resolveRef($branch, $refs);

            if ($this->isNullBranch($branch)) {
                $nullable = true;
            } else {
                $branches[] = [$branch, $branchRefs];
            }
        }

        if ($nullable && count($branches) === 1) {
            return null;
        }

        $this->ensureAnyOfConstraintsAreSupported($schema);

        $type = new Types\AnyOfType(array_map(
            fn (array $branch) => $this->build($branch[0], $branch[1]),
            $branches,
        ));

        if ($nullable) {
            $type->nullable();
        }

        return $type;
    }

    /**
     * Build an object type from the given schema fragment.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $refs
     *
     * @throws InvalidArgumentException
     */
    protected function buildObject(array $schema, array $refs = []): Types\ObjectType
    {
        $properties = [];
        $definitions = [];

        if (array_key_exists('properties', $schema)) {
            $definitions = $schema['properties'];

            if (! is_array($definitions) && ! $definitions instanceof stdClass) {
                throw new InvalidArgumentException('The JSON Schema [properties] keyword must be an object.');
            }
        }

        if ($definitions instanceof stdClass) {
            $definitions = (array) $definitions;
        }

        $required = [];

        if (array_key_exists('required', $schema)) {
            if (! is_array($schema['required'])) {
                throw new InvalidArgumentException('The JSON Schema [required] keyword must be an array of strings.');
            }

            foreach ($schema['required'] as $name) {
                if (! is_string($name)) {
                    throw new InvalidArgumentException('The JSON Schema [required] keyword must be an array of strings.');
                }

                $required[] = $name;
            }
        }

        $requiredLookup = array_flip($required);

        foreach ($definitions as $key => $definition) {
            if (! is_array($definition)) {
                throw new InvalidArgumentException(
                    "Unable to represent the schema for property [{$key}]; boolean schemas are not supported."
                );
            }

            $property = $this->build($definition, $refs);

            if (isset($requiredLookup[(string) $key])) {
                $property->required();
            }

            $properties[$key] = $property;
        }

        foreach ($required as $name) {
            if (! array_key_exists($name, $properties)) {
                throw new InvalidArgumentException(
                    "Unable to represent required property [{$name}] because it has no property schema."
                );
            }
        }

        $type = new Types\ObjectType($properties);

        if (array_key_exists('additionalProperties', $schema)) {
            $additionalProperties = $schema['additionalProperties'];

            if ($additionalProperties === false) {
                $type->withoutAdditionalProperties();
            } elseif ($additionalProperties !== true && $additionalProperties !== []) {
                throw new InvalidArgumentException(
                    'Schema-valued or malformed JSON Schema [additionalProperties] cannot be represented.'
                );
            }
        }

        return $type;
    }

    /**
     * Build an array type from the given schema fragment.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $refs
     *
     * @throws InvalidArgumentException
     */
    protected function buildArray(array $schema, array $refs = []): Types\ArrayType
    {
        $type = new Types\ArrayType;

        if (array_key_exists('items', $schema) && $schema['items'] !== true && $schema['items'] !== []) {
            if (! is_array($schema['items']) || array_is_list($schema['items'])) {
                throw new InvalidArgumentException(
                    'The JSON Schema [items] keyword must be true or a single object schema.'
                );
            }

            $type->items($this->build($schema['items'], $refs));
        }

        $type = $this->applyIntegerBounds($type, $schema, 'minItems', 'maxItems');

        if (array_key_exists('uniqueItems', $schema)) {
            if (! is_bool($schema['uniqueItems'])) {
                throw new InvalidArgumentException('The JSON Schema [uniqueItems] constraint must be a boolean.');
            }

            $type->unique($schema['uniqueItems']);
        }

        return $type;
    }

    /**
     * Build a string type from the given schema fragment.
     *
     * @param array<string, mixed> $schema
     */
    protected function buildString(array $schema): Types\StringType
    {
        $type = new Types\StringType;

        $type = $this->applyIntegerBounds($type, $schema, 'minLength', 'maxLength');

        if (array_key_exists('pattern', $schema)) {
            if (! is_string($schema['pattern'])) {
                throw new InvalidArgumentException('The JSON Schema [pattern] constraint must be a string.');
            }

            $type->pattern($schema['pattern']);
        }

        if (array_key_exists('format', $schema)) {
            if (! is_string($schema['format'])) {
                throw new InvalidArgumentException('The JSON Schema [format] annotation must be a string.');
            }

            $type->format($schema['format']);
        }

        return $type;
    }

    /**
     * Build an integer type from the given schema fragment.
     *
     * @param array<string, mixed> $schema
     */
    protected function buildInteger(array $schema): Types\IntegerType
    {
        return $this->applyNumericBounds(new Types\IntegerType, $schema, $this->toInteger(...));
    }

    /**
     * Build a number type from the given schema fragment.
     *
     * @param array<string, mixed> $schema
     */
    protected function buildNumber(array $schema): Types\NumberType
    {
        return $this->applyNumericBounds(new Types\NumberType, $schema);
    }

    /**
     * Apply the numeric bound keywords to the given integer or number type.
     *
     * @template TType of Types\IntegerType|Types\NumberType
     *
     * @param TType $type
     * @param array<string, mixed> $schema
     * @param null|(callable(float|int): (float|int)) $cast
     * @return TType
     *
     * @throws InvalidArgumentException
     */
    protected function applyNumericBounds(Types\IntegerType|Types\NumberType $type, array $schema, ?callable $cast = null): Types\IntegerType|Types\NumberType
    {
        $cast ??= static fn (int|float $value) => $value;

        foreach (['minimum' => 'min', 'maximum' => 'max', 'multipleOf' => 'multipleOf'] as $keyword => $method) {
            if (! array_key_exists($keyword, $schema)) {
                continue;
            }

            if (($value = $this->toNumber($schema[$keyword])) === null) {
                throw new InvalidArgumentException("The JSON Schema [{$keyword}] constraint must be a number.");
            }

            $type->{$method}($cast($value));
        }

        return $type;
    }

    /**
     * Apply integer-valued minimum and maximum keywords to an array or string type.
     *
     * @template TType of Types\ArrayType|Types\StringType
     *
     * @param TType $type
     * @param array<string, mixed> $schema
     * @return TType
     *
     * @throws InvalidArgumentException
     */
    protected function applyIntegerBounds(Types\ArrayType|Types\StringType $type, array $schema, string $minimumKeyword, string $maximumKeyword): Types\ArrayType|Types\StringType
    {
        foreach ([$minimumKeyword => 'min', $maximumKeyword => 'max'] as $keyword => $method) {
            if (! array_key_exists($keyword, $schema)) {
                continue;
            }

            if (($value = $this->toNumber($schema[$keyword])) === null) {
                throw new InvalidArgumentException("The JSON Schema [{$keyword}] constraint must be an integer.");
            }

            $type->{$method}($this->toInteger($value));
        }

        return $type;
    }

    /**
     * Apply the keywords shared by every type to the given instance.
     *
     * @param array<string, mixed> $schema
     *
     * @throws InvalidArgumentException
     */
    protected function applyCommon(Types\Type $type, array $schema): void
    {
        if (array_key_exists('title', $schema)) {
            if (! is_string($schema['title'])) {
                throw new InvalidArgumentException('The JSON Schema [title] annotation must be a string.');
            }

            $type->title($schema['title']);
        }

        if (array_key_exists('description', $schema)) {
            if (! is_string($schema['description'])) {
                throw new InvalidArgumentException('The JSON Schema [description] annotation must be a string.');
            }

            $type->description($schema['description']);
        }

        if (array_key_exists('enum', $schema)) {
            if (! is_array($schema['enum'])) {
                throw new InvalidArgumentException('The JSON Schema [enum] keyword must be an array.');
            }

            $type->enum($schema['enum']);
        }

        if (array_key_exists('default', $schema)) {
            $default = $schema['default'];

            if ($type instanceof Types\ObjectType && $default instanceof stdClass) {
                $default = (array) $default;
            }

            (fn (mixed $value) => $this->setDefault($value))->call($type, $default);
        }
    }

    /**
     * Resolve the base type name and whether the schema is nullable.
     *
     * @param array<string, mixed> $schema
     * @return array{0: array<int, string>|string, 1: bool}
     *
     * @throws InvalidArgumentException
     */
    protected function resolveType(array $schema): array
    {
        $hasType = array_key_exists('type', $schema);
        $type = $hasType ? $schema['type'] : null;
        $nullable = false;

        if ($hasType && ! is_string($type) && ! is_array($type)) {
            throw new InvalidArgumentException('The JSON Schema [type] keyword must be a string or an array of strings.');
        }

        if ($type === 'null') {
            return [[], true];
        }

        if (is_array($type)) {
            if ($type === []) {
                throw new InvalidArgumentException('A JSON Schema [type] array must contain at least one type.');
            }

            foreach ($type as $name) {
                if (! is_string($name)) {
                    throw new InvalidArgumentException('The JSON Schema [type] keyword must be a string or an array of strings.');
                }
            }

            $nullable = in_array('null', $type, true);

            $names = array_values(array_unique(array_filter(
                $type,
                static fn (string $value) => $value !== 'null',
            )));

            if (count($names) > 1) {
                return [$names, $nullable];
            }

            if ($names === [] && $nullable) {
                return [[], true];
            }

            $type = $names[0] ?? null;
        }

        $type ??= $this->inferType($schema);

        if (! is_string($type)) {
            throw new InvalidArgumentException('Unable to determine the JSON Schema type for the given schema.');
        }

        return [$type, $nullable];
    }

    /**
     * Infer the type name when "type" is absent but the shape is unambiguous.
     *
     * @param array<string, mixed> $schema
     */
    protected function inferType(array $schema): ?string
    {
        return match (true) {
            array_key_exists('properties', $schema), array_key_exists('additionalProperties', $schema), array_key_exists('required', $schema) => 'object',
            array_key_exists('items', $schema), array_key_exists('minItems', $schema), array_key_exists('maxItems', $schema), array_key_exists('uniqueItems', $schema) => 'array',
            array_key_exists('enum', $schema) && is_array($schema['enum']) => $this->inferEnumType($schema['enum']),
            array_key_exists('minLength', $schema), array_key_exists('maxLength', $schema), array_key_exists('pattern', $schema), array_key_exists('format', $schema) => 'string',
            array_key_exists('minimum', $schema), array_key_exists('maximum', $schema), array_key_exists('multipleOf', $schema) => 'number',
            default => null,
        };
    }

    /**
     * Infer the scalar type shared by a homogeneous enum of scalars.
     *
     * @param array<int, mixed> $enum
     */
    protected function inferEnumType(array $enum): ?string
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
                return null;
            }

            if ($resolved === null || $resolved === $current) {
                $resolved = $current;

                continue;
            }

            // A mix of integers and floats is still numeric; anything else is ambiguous...
            if (in_array($resolved, ['integer', 'number'], true) && in_array($current, ['integer', 'number'], true)) {
                $resolved = 'number';

                continue;
            }

            return null;
        }

        return $resolved;
    }

    /**
     * Ensure a multi-type union carries no type-specific constraint keywords.
     *
     * @param array<string, mixed> $schema
     *
     * @throws InvalidArgumentException
     */
    protected function ensureUnionConstraintsAreSupported(array $schema): void
    {
        $unsupported = array_values(array_intersect(static::TYPE_SPECIFIC_KEYWORDS, array_keys($schema)));

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                'Type-specific keywords [' . implode(', ', $unsupported) . '] are not supported on a JSON Schema union.'
            );
        }
    }

    /**
     * Ensure the schema carries no standard assertions this builder cannot represent.
     *
     * @param array<string, mixed> $schema
     *
     * @throws InvalidArgumentException
     */
    protected function ensureAssertionsAreSupported(array $schema): void
    {
        $unsupported = array_values(array_intersect(static::UNSUPPORTED_ASSERTION_KEYWORDS, array_keys($schema)));

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                'Unsupported JSON Schema assertion keywords [' . implode(', ', $unsupported) . '] cannot be represented.'
            );
        }
    }

    /**
     * Ensure a general anyOf composition carries no competing structural constraints.
     *
     * @param array<string, mixed> $schema
     *
     * @throws InvalidArgumentException
     */
    protected function ensureAnyOfConstraintsAreSupported(array $schema): void
    {
        $keywords = [...static::TYPE_SPECIFIC_KEYWORDS, 'type', 'oneOf'];
        $unsupported = array_values(array_intersect($keywords, array_keys($schema)));

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                'Structural keywords [' . implode(', ', $unsupported) . '] are not supported alongside a general JSON Schema anyOf.'
            );
        }
    }

    /**
     * Collapse "anyOf" / "oneOf" null branches into a single effective schema.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $refs
     * @return array{0: array<string, mixed>, 1: bool, 2: array<int, string>}
     *
     * @throws InvalidArgumentException
     */
    protected function normalizeUnions(array $schema, array $refs = []): array
    {
        foreach (['anyOf', 'oneOf'] as $key) {
            if (! array_key_exists($key, $schema)) {
                continue;
            }

            if (! is_array($schema[$key]) || $schema[$key] === []) {
                throw new InvalidArgumentException("The JSON Schema [{$key}] keyword must be a non-empty array.");
            }

            $nullable = false;
            $branches = [];

            foreach ($schema[$key] as $branch) {
                if (! is_array($branch)) {
                    throw new InvalidArgumentException(
                        "Unable to represent the schema for a {$key} branch; boolean schemas are not supported."
                    );
                }

                [$branch, $branchRefs] = $this->resolveRef($branch, $refs);

                if ($this->isNullBranch($branch)) {
                    $nullable = true;
                } else {
                    $branches[] = [$branch, $branchRefs];
                }
            }

            if (! $nullable || count($branches) !== 1) {
                throw new InvalidArgumentException(
                    "Only a nullable \"{$key}\" (a single schema plus a bare \"null\" branch) is supported."
                );
            }

            [$branch, $branchRefs] = $branches[0];

            $siblings = $schema;
            unset($siblings[$key]);

            foreach ($siblings as $siblingKey => $value) {
                if (array_key_exists($siblingKey, $branch) && $branch[$siblingKey] !== $value) {
                    throw new InvalidArgumentException(
                        "Conflicting [{$siblingKey}] between a \"{$key}\" branch and its sibling keys."
                    );
                }
            }

            $merged = array_merge($siblings, $branch);
            $compositions = array_values(array_intersect(['anyOf', 'oneOf'], array_keys($merged)));

            if ($compositions !== []) {
                throw new InvalidArgumentException(
                    'Structural keywords [' . implode(', ', $compositions)
                    . "] are not supported alongside a nullable \"{$key}\"."
                );
            }

            return [$merged, true, $branchRefs];
        }

        return [$schema, false, $refs];
    }

    /**
     * Determine if the given schema branch describes only the "null" type.
     *
     * @param array<string, mixed> $branch
     */
    protected function isNullBranch(array $branch): bool
    {
        if (count($branch) !== 1 || ! array_key_exists('type', $branch)) {
            return false;
        }

        $type = $branch['type'] ?? null;

        return $type === 'null' || $type === ['null'];
    }

    /**
     * Resolve a local "$ref" against the root schema, merging sibling keys.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $refs
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     *
     * @throws InvalidArgumentException
     */
    protected function resolveRef(array $schema, array $refs = []): array
    {
        while (array_key_exists('$ref', $schema)) {
            if (! is_string($schema['$ref'])) {
                throw new InvalidArgumentException('The JSON Schema [$ref] keyword must be a string.');
            }

            $ref = $schema['$ref'];

            if (in_array($ref, $refs, true)) {
                throw new InvalidArgumentException("Circular JSON Schema \$ref [{$ref}] detected.");
            }

            if (count($refs) >= static::MAX_REFERENCE_DEPTH) {
                throw new InvalidArgumentException(
                    'JSON Schema reference paths may not contain more than ' . static::MAX_REFERENCE_DEPTH . ' distinct references.'
                );
            }

            $this->countNode();
            $refs[] = $ref;

            $resolved = $this->lookupRef($ref);
            unset($schema['$ref']);
            $schema = array_merge($resolved, $schema);
        }

        return [$schema, $refs];
    }

    /**
     * Look up a local JSON pointer reference within the root schema.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    protected function lookupRef(string $ref): array
    {
        if (isset($this->refCache[$ref])) {
            return $this->refCache[$ref];
        }

        if ($ref === '#') {
            return $this->refCache[$ref] = $this->root;
        }

        if (! str_starts_with($ref, '#/')) {
            throw new InvalidArgumentException("Unable to resolve non-local JSON Schema \$ref [{$ref}].");
        }

        $target = $this->root;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                throw new InvalidArgumentException("Unable to resolve JSON Schema \$ref [{$ref}].");
            }

            $target = $target[$segment];
        }

        if (! is_array($target)) {
            throw new InvalidArgumentException("The JSON Schema \$ref [{$ref}] does not point to a schema.");
        }

        return $this->refCache[$ref] = $target;
    }

    /**
     * Normalize the given value to an integer or float, or null when not numeric.
     */
    protected function toNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return $value + 0;
        }

        return null;
    }

    /**
     * Normalize the given number to a PHP integer.
     *
     * @throws InvalidArgumentException
     */
    protected function toInteger(int|float $value): int
    {
        if (is_float($value) && floor($value) !== $value) {
            throw new InvalidArgumentException("The JSON Schema integer constraint [{$value}] must be an integer.");
        }

        if (is_float($value) && ($value < (float) PHP_INT_MIN || $value >= (float) PHP_INT_MAX)) {
            throw new InvalidArgumentException('The JSON Schema integer constraint is outside the PHP integer range.');
        }

        return (int) $value;
    }

    /**
     * Count an expanded schema fragment.
     *
     * @throws InvalidArgumentException
     */
    protected function countNode(): void
    {
        if (++$this->nodes > static::MAX_NODES) {
            throw new InvalidArgumentException(
                'JSON Schema reconstruction exceeded the maximum expansion of ' . static::MAX_NODES . ' schema fragments.'
            );
        }
    }
}
