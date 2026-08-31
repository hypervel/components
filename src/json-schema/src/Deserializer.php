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
    protected const array TYPE_SPECIFIC_KEYWORDS = [
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
    protected const array UNSUPPORTED_ASSERTION_KEYWORDS = [
        'const', 'not', 'allOf', 'if', 'dependentSchemas', 'dependentRequired',
        'prefixItems', 'contains', 'patternProperties', 'propertyNames',
        'unevaluatedItems', 'unevaluatedProperties',
        'exclusiveMinimum', 'exclusiveMaximum', 'minProperties', 'maxProperties',
        '$dynamicRef',
    ];

    /**
     * The maximum number of schema fragments that may be expanded.
     */
    protected const int MAX_NODES = 20000;

    /**
     * The maximum number of distinct references on one active path.
     */
    protected const int MAX_REFERENCE_DEPTH = 256;

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

        $composition = $this->prepareComposition($schema, $refs);

        if (($type = $this->buildAnyOfComposition($schema, $refs, $composition)) !== null) {
            $this->applyCommon($type, $schema);

            return $type;
        }

        if ($composition === null) {
            $nullableFromUnion = false;
        } else {
            [$schema, $nullableFromUnion, $refs] = $this->normalizeUnions($schema, $refs, $composition);
        }

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
     * Prepare the branches of an anyOf or oneOf composition.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $refs
     * @return null|array{
     *     keyword: 'anyOf'|'oneOf',
     *     branches: array<int, array{0: array<string, mixed>, 1: array<int, string>}>,
     *     nullBranches: int
     * }
     *
     * @throws InvalidArgumentException
     */
    protected function prepareComposition(array $schema, array $refs = []): ?array
    {
        $keyword = match (true) {
            array_key_exists('anyOf', $schema) => 'anyOf',
            array_key_exists('oneOf', $schema) => 'oneOf',
            default => null,
        };

        if ($keyword === null) {
            return null;
        }

        if (! is_array($schema[$keyword]) || $schema[$keyword] === []) {
            throw new InvalidArgumentException("The JSON Schema [{$keyword}] keyword must be a non-empty array.");
        }

        $nullBranches = 0;
        $branches = [];

        foreach ($schema[$keyword] as $branch) {
            $branch = $this->ensureSchemaFragmentIsArray(
                $branch,
                $this->describeBranch($keyword),
            );

            [$branch, $branchRefs] = $this->resolveRef($branch, $refs);

            if ($this->isNullBranch($branch)) {
                ++$nullBranches;
            } else {
                $branches[] = [$branch, $branchRefs];
            }
        }

        return [
            'keyword' => $keyword,
            'branches' => $branches,
            'nullBranches' => $nullBranches,
        ];
    }

    /**
     * Build an anyOf composition unless it is the existing nullable single-schema form.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $refs
     * @param null|array{
     *     keyword: 'anyOf'|'oneOf',
     *     branches: array<int, array{0: array<string, mixed>, 1: array<int, string>}>,
     *     nullBranches: int
     * } $composition
     *
     * @throws InvalidArgumentException
     */
    protected function buildAnyOfComposition(array $schema, array $refs = [], ?array $composition = null): ?Types\AnyOfType
    {
        if (! array_key_exists('anyOf', $schema)) {
            return null;
        }

        $composition ??= $this->prepareComposition($schema, $refs);
        $nullable = $composition['nullBranches'] > 0;
        $branches = $composition['branches'];

        if ($nullable
            && count($branches) === 1
            && ! $this->hasBranchOnlyEnumExcludingNull($schema, $branches[0][0])) {
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
            $definition = $this->ensureSchemaFragmentIsArray($definition, "property [{$key}]");

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
            $items = $this->ensureSchemaFragmentIsArray($schema['items'], 'the [items] keyword');

            if (array_is_list($items)) {
                throw new InvalidArgumentException(
                    'The JSON Schema [items] keyword must be true or a single object schema.'
                );
            }

            $type->items($this->build($items, $refs));
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
     * Describe a composition branch.
     */
    protected function describeBranch(string $keyword): string
    {
        return ($keyword === 'anyOf' ? 'an ' : 'a ') . $keyword . ' branch';
    }

    /**
     * Ensure the given schema fragment is an array.
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException
     */
    protected function ensureSchemaFragmentIsArray(mixed $fragment, string $context): array
    {
        if (is_bool($fragment)) {
            throw new InvalidArgumentException(
                "Unable to represent the schema for {$context}; boolean schemas are not supported."
            );
        }

        if (! is_array($fragment)) {
            throw new InvalidArgumentException(
                "Unable to represent the schema for {$context}; the schema fragment must be an array, "
                . get_debug_type($fragment) . ' given.'
            );
        }

        return $fragment;
    }

    /**
     * Merge two schema fragments without weakening represented assertions.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    protected function mergeSchemaFragments(array $base, array $overlay, string $context): array
    {
        $representedAssertions = [
            ...static::TYPE_SPECIFIC_KEYWORDS,
            'type',
            'enum',
            'anyOf',
            'oneOf',
        ];

        foreach ($overlay as $keyword => $value) {
            if (! array_key_exists($keyword, $base) || $base[$keyword] === $value) {
                continue;
            }

            if (in_array($keyword, ['title', 'description', 'default'], true)) {
                continue;
            }

            if (in_array($keyword, $representedAssertions, true)) {
                throw new InvalidArgumentException("Conflicting [{$keyword}] between {$context}.");
            }
        }

        return array_merge($base, $overlay);
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
     * @param null|array{
     *     keyword: 'anyOf'|'oneOf',
     *     branches: array<int, array{0: array<string, mixed>, 1: array<int, string>}>,
     *     nullBranches: int
     * } $composition
     * @return array{0: array<string, mixed>, 1: bool, 2: array<int, string>}
     *
     * @throws InvalidArgumentException
     */
    protected function normalizeUnions(array $schema, array $refs = [], ?array $composition = null): array
    {
        $composition ??= $this->prepareComposition($schema, $refs);

        if ($composition !== null) {
            $keyword = $composition['keyword'];
            $nullBranches = $composition['nullBranches'];
            $branches = $composition['branches'];

            // "oneOf" accepts an instance only when exactly one branch matches, so its nullable
            // collapse must not allow a second null match.
            if ($keyword === 'oneOf' && $nullBranches > 1) {
                throw new InvalidArgumentException(
                    'A nullable "oneOf" must contain exactly one bare "null" branch.'
                );
            }

            if ($nullBranches === 0 || count($branches) !== 1) {
                throw new InvalidArgumentException(
                    "Only a nullable \"{$keyword}\" (a single schema plus a bare \"null\" branch) is supported."
                );
            }

            [$branch, $branchRefs] = $branches[0];

            if ($keyword === 'oneOf' && $this->mayAcceptNull($branch)) {
                throw new InvalidArgumentException(
                    'A nullable "oneOf" schema branch must declare a type that excludes "null".'
                );
            }

            if ($keyword === 'oneOf' && $this->hasBranchOnlyEnumExcludingNull($schema, $branch)) {
                throw new InvalidArgumentException(
                    'A branch-local [enum] that excludes null cannot be collapsed from a nullable "oneOf"; '
                    . 'include null in the enum or use an equivalent "anyOf" composition.'
                );
            }

            $siblings = $schema;
            unset($siblings[$keyword]);

            $compositions = array_values(array_intersect(
                ['anyOf', 'oneOf'],
                [...array_keys($branch), ...array_keys($siblings)],
            ));

            if ($compositions !== []) {
                throw new InvalidArgumentException(
                    'Structural keywords [' . implode(', ', $compositions)
                    . "] are not supported alongside a nullable \"{$keyword}\"."
                );
            }

            $merged = $this->mergeSchemaFragments(
                $branch,
                $siblings,
                $this->describeBranch($keyword) . ' and its sibling keys',
            );

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

        $type = $branch['type'];

        return $type === 'null' || $type === ['null'];
    }

    /**
     * Determine if the schema branch may accept null.
     *
     * @param array<string, mixed> $branch
     */
    protected function mayAcceptNull(array $branch): bool
    {
        if (! array_key_exists('type', $branch)) {
            // Without "type", the branch places no constraint on the instance type and matches null.
            return true;
        }

        $type = $branch['type'];

        return $type === 'null'
            || (is_array($type) && in_array('null', $type, true));
    }

    /**
     * Determine if the branch alone constrains an enum that excludes null.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $branch
     */
    protected function hasBranchOnlyEnumExcludingNull(array $schema, array $branch): bool
    {
        return ! array_key_exists('enum', $schema)
            && array_key_exists('enum', $branch)
            && is_array($branch['enum'])
            && ! in_array(null, $branch['enum'], true);
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
            $schema = $this->mergeSchemaFragments(
                $resolved,
                $schema,
                "the local \$ref [{$ref}] target and its sibling keys",
            );
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
