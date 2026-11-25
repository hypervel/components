<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema;

use Hypervel\JsonSchema\Rules\DistinctArrayItems;
use Hypervel\JsonSchema\Rules\MatchesAnyOf;
use Hypervel\JsonSchema\Rules\MatchesEnum;
use Hypervel\JsonSchema\Rules\RejectEmptyString;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Support\Arr;
use Hypervel\Support\Number;
use Hypervel\Validation\Rule;
use InvalidArgumentException;

class ValidationRules
{
    /**
     * The JSON Schema assertions that no validation rule enforces.
     *
     * @var array<int, string>
     */
    protected const array UNSUPPORTED_KEYWORDS = [
        '$ref', '$dynamicRef', 'oneOf', 'allOf', 'not', 'if', 'const',
        'dependentSchemas', 'dependentRequired', 'dependencies',
        'prefixItems', 'additionalItems', 'contains', 'unevaluatedItems',
        'patternProperties', 'propertyNames', 'unevaluatedProperties', 'minProperties', 'maxProperties',
    ];

    /**
     * The keywords that only constrain values of one type.
     *
     * @var array<int, string>
     */
    protected const array TYPE_SPECIFIC_KEYWORDS = [
        'minLength', 'maxLength', 'pattern', 'format',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'items', 'minItems', 'maxItems', 'uniqueItems',
        'properties', 'required', 'additionalProperties',
    ];

    /**
     * The number of schema fragments examined so far.
     */
    protected int $nodes = 0;

    /**
     * Get the validation rules for the properties of the given object schema.
     *
     * @param array<string, mixed>|Type $schema
     * @return array<string, array<int, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public static function for(Type|array $schema): array
    {
        $converter = new static;
        $schema = $schema instanceof Type ? $schema->toArray() : $schema;

        $path = [];

        $converter->ensureConvertible($schema, $path);

        // Conversion gets its own budget, since a multi-type schema expands into a branch per type.
        $converter->nodes = 0;

        return $converter->properties($schema);
    }

    /**
     * Ensure every assertion in the schema can be converted to validation rules.
     *
     * Unknown keywords and formats are annotations, but a recognized assertion without a rule
     * would otherwise be dropped silently and let invalid values pass.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $path the escaped JSON pointer segments of the schema being checked
     * @param null|string $segment the segment that leads from the parent schema to this one, or null for the root
     *
     * @throws InvalidArgumentException
     */
    protected function ensureConvertible(array $schema, array &$path, ?string $segment = null): void
    {
        // Like conversion, count the fragments below the root.
        if ($segment !== null) {
            $path[] = $segment;

            $this->countNode();
        }

        foreach (static::UNSUPPORTED_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                throw $this->unsupportedKeyword($keyword, $path);
            }
        }

        // A schema may also be given as an object.
        $additionalProperties = $schema['additionalProperties'] ?? false;

        if (! is_bool($additionalProperties) && (array) $additionalProperties !== []) {
            throw $this->unsupportedKeyword('additionalProperties', $path);
        }

        if (is_array($schema['items'] ?? null) && $schema['items'] !== [] && array_is_list($schema['items'])) {
            throw $this->unsupportedKeyword('items', $path);
        }

        if ($segment === null) {
            // Rules can only be keyed by the root object's properties.
            if (isset($schema['type']) && $this->types($schema) !== ['object']) {
                throw $this->unsupportedKeyword('type', $path);
            }

            foreach (['anyOf', 'enum'] as $keyword) {
                if (array_key_exists($keyword, $schema)) {
                    throw $this->unsupportedKeyword($keyword, $path);
                }
            }
        } elseif (! isset($schema['type'])) {
            foreach (static::TYPE_SPECIFIC_KEYWORDS as $keyword) {
                if (array_key_exists($keyword, $schema) && ($keyword !== 'format' || $this->formatRules($schema['format']) !== [])) {
                    throw new InvalidArgumentException(
                        "The JSON Schema keyword [{$keyword}] at [" . implode('/', ['#', ...$path]) . '] requires a [type] to be converted to validation rules.'
                    );
                }
            }
        }

        foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
            $this->ensureConvertible($property, $path, 'properties/' . str_replace(['~', '/'], ['~0', '~1'], (string) $name));
        }

        if (is_array($schema['items'] ?? null)) {
            $this->ensureConvertible($schema['items'], $path, 'items');
        }

        foreach ($schema['anyOf'] ?? [] as $index => $branch) {
            $this->ensureConvertible($branch, $path, "anyOf/{$index}");
        }

        if ($segment !== null) {
            array_pop($path);
        }
    }

    /**
     * Get the rules for each property of an object schema, keyed under the given prefix.
     *
     * @param array<string, mixed> $schema
     * @return array<string, array<int, mixed>>
     */
    protected function properties(array $schema, ?string $prefix = null): array
    {
        $required = array_fill_keys($schema['required'] ?? [], true);

        $rules = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $attribute = str_replace(['.', '*'], ['\.', '\*'], (string) $name);

            if (! is_null($prefix)) {
                $attribute = "{$prefix}.{$attribute}";
            }

            $rules += $this->rules(
                $property,
                $attribute,
                isset($required[$name]),
                ! is_null($prefix),
            );

            unset($required[$name]);
        }

        // Nested objects require their keys through "required_array_keys", which the root has no rule for.
        if (is_null($prefix)) {
            foreach (array_keys($required) as $name) {
                $rules[str_replace(['.', '*'], ['\.', '\*'], (string) $name)] = ['present'];
            }
        }

        return $rules;
    }

    /**
     * Get the rules for one value and everything nested inside it.
     *
     * @param array<string, mixed> $schema
     * @return array<string, array<int, mixed>>
     */
    protected function rules(array $schema, string $attribute, bool $required, bool $nested = false): array
    {
        $this->countNode();

        if (isset($schema['anyOf'])) {
            $anyOf = $this->anyOf($schema['anyOf']);

            // Pass on the converted branches' nullability, since walking nested "anyOf"
            // branches again at each level repeats work and can exhaust the fragment limit early.
            $nullable = $this->isNullable($schema, $anyOf->allowsNull());

            // The keywords beside "anyOf" constrain the value too.
            $rules = $this->valueRules(Arr::except($schema, 'anyOf'), $attribute);

            return [$attribute => [...$this->presenceRules($nullable, $required, $nested), $anyOf, ...$rules[$attribute]]] + $rules;
        }

        $rules = $this->valueRules($schema, $attribute);

        return [$attribute => [...$this->presenceRules($this->isNullable($schema), $required, $nested), ...$rules[$attribute]]] + $rules;
    }

    /**
     * Get the type, constraint, and nested rules for the value, keyed by attribute.
     *
     * @param array<string, mixed> $schema
     * @return array<string, array<int, mixed>>
     */
    protected function valueRules(array $schema, string $attribute): array
    {
        $types = $this->types($schema);

        if (count($types) > 1) {
            $branches = array_map(fn (string $type) => [...$schema, 'type' => $type], $types);

            return [$attribute => [$this->anyOf($branches)]];
        }

        // An explicit "null" type allows nothing else, and the presence rules let null through.
        if ($types === [] && isset($schema['type'])) {
            return [$attribute => [Rule::in([null]), ...$this->enumRules($schema)]];
        }

        $type = $types[0] ?? null;
        $nested = $this->nestedRules($type, $schema, $attribute);

        return [$attribute => [...$this->typeRules($type, $schema), ...$nested[$attribute] ?? []]] + $nested;
    }

    /**
     * Get the rules that decide when the value must be present.
     *
     * @return array<int, mixed>
     */
    protected function presenceRules(bool $nullable, bool $required, bool $nested): array
    {
        if ($required && ! $nested) {
            return $nullable ? ['present', 'nullable', new RejectEmptyString] : ['required'];
        }

        if ($nullable) {
            return ['sometimes', 'nullable', new RejectEmptyString];
        }

        // A nested required key is enforced by its parent's "required_array_keys", so an absent parent passes...
        return $required ? ['sometimes', 'filled'] : ['sometimes', new RejectEmptyString];
    }

    /**
     * Get the rules for the items or properties nested inside the value.
     *
     * @param array<string, mixed> $schema
     * @return array<string, array<int, mixed>>
     */
    protected function nestedRules(?string $type, array $schema, string $attribute): array
    {
        if ($type === 'object') {
            return $this->properties($schema, $attribute);
        }

        if ($type !== 'array') {
            return [];
        }

        // "true" and an empty schema allow any item, and arrayRules() enforces that "false" allows none.
        $items = array_key_exists('items', $schema) ? $schema['items'] : true;
        $rules = in_array($items, [true, false, []], true) ? [] : $this->rules($items, "{$attribute}.*", false);

        if ($schema['uniqueItems'] ?? false) {
            // "distinct" on a wildcard compares items across every parent list, so each list gets its own rule.
            // An asterisk is a wildcard unless it is escaped, as in a property name.
            if (preg_match('/(?<!\\\)\*/', $attribute) === 1) {
                $rules[$attribute] = [Rule::forEach(static fn () => ['*' => ['distinct:strict']])];
            } else {
                $rules["{$attribute}.*"][] = 'distinct:strict';
            }
        }

        return $rules;
    }

    /**
     * Get the rules a value of the given type must pass.
     *
     * @param array<string, mixed> $schema
     * @return array<int, mixed>
     */
    protected function typeRules(?string $type, array $schema): array
    {
        $rules = match ($type) {
            'string' => ['string', ...$this->stringRules($schema)],
            'integer' => ['integer', ...$this->numberRules($schema)],
            'number' => ['numeric', ...$this->numberRules($schema)],
            'boolean' => ['boolean'],
            'array' => $this->arrayRules($schema),
            'object' => $this->objectRules($schema),
            default => [],
        };

        return [...$rules, ...$this->enumRules($schema)];
    }

    /**
     * Get the length, pattern, and format rules for a string schema.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    protected function stringRules(array $schema): array
    {
        $rules = $this->bounds($schema, 'minLength', 'maxLength');

        if (isset($schema['pattern'])) {
            $rules[] = 'regex:/' . $this->escapeDelimiter($schema['pattern']) . '/u';
        }

        return [...$rules, ...$this->formatRules($schema['format'] ?? null)];
    }

    /**
     * Get the rules for the given string format.
     *
     * @return array<int, string>
     */
    protected function formatRules(?string $format): array
    {
        return match ($format) {
            'email' => ['email'],
            'uri', 'url' => ['url'],
            'uuid' => ['uuid'],
            'date' => ['date_format:Y-m-d'],
            'date-time' => ['regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/i', 'date'],
            default => [],
        };
    }

    /**
     * Get the bound and "multiple_of" rules for a numeric schema.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    protected function numberRules(array $schema): array
    {
        $rules = $this->bounds($schema, 'minimum', 'maximum');

        // Without "literal", an input field named like the limit would be compared instead.
        if (isset($schema['exclusiveMinimum']) && is_numeric($schema['exclusiveMinimum'])) {
            $rules[] = 'gt:' . Number::toString($schema['exclusiveMinimum']) . ',literal';
        }

        if (isset($schema['exclusiveMaximum']) && is_numeric($schema['exclusiveMaximum'])) {
            $rules[] = 'lt:' . Number::toString($schema['exclusiveMaximum']) . ',literal';
        }

        if (isset($schema['multipleOf'])) {
            $rules[] = 'multiple_of:' . Number::toString($schema['multipleOf']);
        }

        return $rules;
    }

    /**
     * Get the rules an array value must pass.
     *
     * @param array<string, mixed> $schema
     * @return array<int, mixed>
     */
    protected function arrayRules(array $schema): array
    {
        $rules = ['list', ...$this->bounds($schema, 'minItems', 'maxItems')];

        if (($schema['items'] ?? null) === false) {
            $rules[] = 'max:0';
        }

        if ($schema['uniqueItems'] ?? false) {
            $rules[] = new DistinctArrayItems;
        }

        return $rules;
    }

    /**
     * Get the rules an object value must pass.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    protected function objectRules(array $schema): array
    {
        $rules = ['array'];

        if (($schema['additionalProperties'] ?? null) === false) {
            // The serializer keeps a property map with list-like keys as an object.
            $keys = array_map('strval', array_keys((array) ($schema['properties'] ?? [])));

            $rules = $keys === [] ? ['array', 'max:0'] : ['array:' . $this->parameters($keys)];
        }

        if (! empty($schema['required'])) {
            $rules[] = 'required_array_keys:' . $this->parameters(array_map('strval', $schema['required']));
        }

        return $rules;
    }

    /**
     * Get the rule that restricts the value to the schema's enumerated values.
     *
     * @param array<string, mixed> $schema
     * @return array<int, mixed>
     */
    protected function enumRules(array $schema): array
    {
        if (! isset($schema['enum'])) {
            return [];
        }

        // With the "array" rule, "in" checks each member of an array value instead of the whole value.
        $comparesWholeArrays = Arr::some($schema['enum'], fn (mixed $value) => is_array($value))
            || in_array('object', Arr::wrap($schema['type'] ?? null), true);

        return $comparesWholeArrays
            ? [new MatchesEnum($schema['enum'])]
            : [Rule::in($schema['enum'])];
    }

    /**
     * Get the rule that passes when the value matches any of the given schemas.
     *
     * @param array<int, array<string, mixed>> $schemas
     */
    protected function anyOf(array $schemas): MatchesAnyOf
    {
        // Unlike Laravel, null-only branches are converted too, since they only accept null
        // and rules() derives the value's nullability from the converted branches.
        // The value is already present, and "required" would also reject an empty array.
        return new MatchesAnyOf(array_map(
            fn (array $schema) => $this->rules($schema, 'value', false),
            $schemas,
        ));
    }

    /**
     * Get the "min" and "max" rules for the given schema keywords.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    protected function bounds(array $schema, string $min, string $max): array
    {
        $rules = [];

        // String interpolation would round float bounds to PHP's display precision.
        if (isset($schema[$min])) {
            $rules[] = 'min:' . Number::toString($schema[$min]);
        }

        if (isset($schema[$max])) {
            $rules[] = 'max:' . Number::toString($schema[$max]);
        }

        return $rules;
    }

    /**
     * Get the types the schema allows, other than "null".
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    protected function types(array $schema): array
    {
        return array_values(array_diff(Arr::wrap($schema['type'] ?? null), ['null']));
    }

    /**
     * Determine if the schema allows a null value.
     *
     * @param array<string, mixed> $schema
     * @param bool $branchesAllowNull whether a converted "anyOf" branch allows null
     */
    protected function isNullable(array $schema, bool $branchesAllowNull = false): bool
    {
        if (isset($schema['enum']) && ! in_array(null, $schema['enum'], true)) {
            return false;
        }

        $types = Arr::wrap($schema['type'] ?? null);

        // Unlike Laravel, an untyped schema allows null, since JSON Schema's "required" only governs
        // key presence. Null must also satisfy one of the "anyOf" branches.
        return ($types === [] || in_array('null', $types, true))
            && (! isset($schema['anyOf']) || $branchesAllowNull);
    }

    /**
     * Count a schema fragment against the conversion limit.
     *
     * @throws InvalidArgumentException
     */
    protected function countNode(): void
    {
        if (++$this->nodes > JsonSchema::MAX_NODES) {
            throw new InvalidArgumentException(
                'The JSON Schema is too large to convert to validation rules; it expands beyond [' . JsonSchema::MAX_NODES . '] fragments.'
            );
        }
    }

    /**
     * Create an exception for a keyword that cannot be converted to validation rules.
     *
     * @param array<int, string> $path the escaped JSON pointer segments of the schema using the keyword
     */
    protected function unsupportedKeyword(string $keyword, array $path): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "The JSON Schema keyword [{$keyword}] at [" . implode('/', ['#', ...$path]) . '] cannot be converted to validation rules.'
        );
    }

    /**
     * Join the given values into a quoted rule parameter list.
     *
     * @param array<int, string> $values
     */
    protected function parameters(array $values): string
    {
        return implode(',', array_map(fn (string $value): string => '"' . str_replace('"', '""', $value) . '"', $values));
    }

    /**
     * Escape each "/" in the pattern that is not already escaped by an odd number of backslashes.
     */
    protected function escapeDelimiter(string $pattern): string
    {
        return preg_replace('~(?<!\\\)((?:\\\\\\\)*)/~', '$1\/', $pattern);
    }
}
