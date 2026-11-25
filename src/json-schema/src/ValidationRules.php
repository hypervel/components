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
     * The number of schema fragments converted so far.
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
        return (new static)->properties($schema instanceof Type ? $schema->toArray() : $schema);
    }

    /**
     * Get the rules for each property of an object schema, keyed under the given prefix.
     *
     * @param array<string, mixed> $schema
     * @return array<string, array<int, mixed>>
     */
    protected function properties(array $schema, ?string $prefix = null): array
    {
        $required = array_map('strval', $schema['required'] ?? []);

        $rules = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $attribute = str_replace('.', '\.', (string) $name);

            if (! is_null($prefix)) {
                $attribute = "{$prefix}.{$attribute}";
            }

            $rules += $this->rules(
                $property,
                $attribute,
                in_array((string) $name, $required, true),
                ! is_null($prefix),
            );
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
        if (++$this->nodes > JsonSchema::MAX_NODES) {
            throw new InvalidArgumentException(
                'The JSON Schema is too large to convert to validation rules; it expands beyond [' . JsonSchema::MAX_NODES . '] fragments.'
            );
        }

        if (isset($schema['anyOf'])) {
            $branches = $this->branches($schema['anyOf']);
            $rules = $this->presenceRules($this->isNullable($schema, $branches), $required, $nested);

            return [$attribute => [...$rules, new MatchesAnyOf($branches), ...$this->enumRules($schema)]];
        }

        $rules = $this->presenceRules($this->isNullable($schema), $required, $nested);
        $types = $this->types($schema);

        if (count($types) > 1) {
            $branches = array_map(fn (string $type) => [...$schema, 'type' => $type], $types);

            return [$attribute => [...$rules, $this->anyOf($branches)]];
        }

        $type = $types[0] ?? null;

        return [$attribute => [...$rules, ...$this->typeRules($type, $schema, $attribute)]]
            + $this->nestedRules($type, $schema, $attribute);
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

        $rules = isset($schema['items']) ? $this->rules($schema['items'], "{$attribute}.*", false) : [];

        // Lists inside a wildcard compare their scalar items through arrayRules().
        if (($schema['uniqueItems'] ?? false) && ! str_contains($attribute, '*')) {
            $rules["{$attribute}.*"][] = 'distinct:strict';
        }

        return $rules;
    }

    /**
     * Get the rules a value of the given type must pass.
     *
     * @param array<string, mixed> $schema
     * @return array<int, mixed>
     */
    protected function typeRules(?string $type, array $schema, string $attribute): array
    {
        $rules = match ($type) {
            'string' => ['string', ...$this->stringRules($schema)],
            'integer' => ['integer', ...$this->numberRules($schema)],
            'number' => ['numeric', ...$this->numberRules($schema)],
            'boolean' => ['boolean'],
            'array' => $this->arrayRules($schema, $attribute),
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
    protected function arrayRules(array $schema, string $attribute): array
    {
        $rules = ['list', ...$this->bounds($schema, 'minItems', 'maxItems')];

        if ($schema['uniqueItems'] ?? false) {
            $rules[] = new DistinctArrayItems;

            // "distinct" on a nested wildcard compares items across every parent list, so each list gets its own rule.
            if (str_contains($attribute, '*')) {
                $rules[] = Rule::forEach(static fn () => ['*' => ['distinct:strict']]);
            }
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
            $keys = array_map('strval', array_keys($schema['properties'] ?? []));

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

        return Arr::some($schema['enum'], fn (mixed $value) => is_array($value))
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
        return new MatchesAnyOf($this->branches($schemas));
    }

    /**
     * Get the rules for each non-null branch, keyed under "value".
     *
     * @param array<int, array<string, mixed>> $schemas
     * @return array<int, array<string, array<int, mixed>>>
     */
    protected function branches(array $schemas): array
    {
        $schemas = array_filter($schemas, fn (array $schema) => ($schema['type'] ?? null) !== 'null');

        return array_values(array_map(
            fn (array $schema) => $this->rules($schema, 'value', true),
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
     * @param array<int, array<string, array<int, mixed>>> $branches the converted non-null "anyOf" branches
     */
    protected function isNullable(array $schema, array $branches = []): bool
    {
        if (isset($schema['enum']) && ! in_array(null, $schema['enum'], true)) {
            return false;
        }

        // Null-only branches are not converted; a converted branch allows null exactly when its presence rules include "nullable".
        return in_array('null', Arr::wrap($schema['type'] ?? null), true)
            || Arr::some($schema['anyOf'] ?? [], fn (array $branch) => ($branch['type'] ?? null) === 'null' && $this->isNullable($branch))
            || Arr::some($branches, fn (array $rules) => in_array('nullable', $rules['value'], true));
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
