<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema\Rules;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Translation\PotentiallyTranslatedString;
use stdClass;

class DistinctArrayItems implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Hypervel's "distinct" rule does not compare array or object items by content, and it skips blank strings and nullable null items, so this rule compares those.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $seen = [];

        foreach ((array) $value as $item) {
            if (! is_array($item) && ! $item instanceof stdClass && $item !== null && ! (is_string($item) && trim($item) === '')) {
                continue;
            }

            $key = static::key(static::normalize($item));

            if (isset($seen[$key])) {
                $fail('validation.distinct')->translate();

                return;
            }

            $seen[$key] = true;
        }
    }

    /**
     * Get a key that two values share only when they are strictly equal.
     */
    protected static function key(mixed $value): string
    {
        if (is_array($value)) {
            $key = 'a' . count($value) . '{';

            foreach ($value as $itemKey => $item) {
                $key .= static::key($itemKey) . static::key($item);
            }

            return $key . '}';
        }

        return match (true) {
            is_string($value) => 's' . strlen($value) . ':' . $value,
            is_int($value) => 'i' . $value . ';',
            // PHP considers the two signed zeros equal.
            is_float($value) => 'f' . pack('e', $value === 0.0 ? 0.0 : $value),
            is_bool($value) => $value ? 'b1' : 'b0',
            is_null($value) => 'n',
            default => 'o' . spl_object_id($value) . ';',
        };
    }

    /**
     * Sort the keys of every object in the value so that key order does not affect strict comparison.
     *
     * A JSON object may be given as a stdClass, which request data represents as an array.
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(static::normalize(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
