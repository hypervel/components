<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

use Hypervel\Validation\ValidationData;

/**
 * Immutable output from one complete validation-rule compilation.
 */
final readonly class CompiledValidation
{
    /**
     * Create a compiled validation result.
     *
     * @param array<string, list<array|object|string>> $rules
     * @param array<string, array<string, string>|string> $messages
     * @param array<string, string> $attributes
     * @param list<ValidationPath> $preservedPaths
     * @param list<string> $additionalFields
     * @param list<string> $allowedSubtrees
     */
    public function __construct(
        public array $rules,
        public array $messages = [],
        public array $attributes = [],
        public array $preservedPaths = [],
        public array $additionalFields = [],
        public array $allowedSubtrees = [],
    ) {
    }

    /**
     * Restore only values deliberately excluded from validation.
     *
     * Values come from the validator's own data, which has already dropped everything an exclusion
     * rule removed, so an excluded parent is never recreated.
     *
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed> $validatorData the validator's data, with its encoded keys
     * @return array<array-key, mixed>
     */
    public function restorePreservedValues(array $payload, array $validatorData): array
    {
        foreach ($this->preservedPaths as $path) {
            $this->restoreValueAtPath(
                $payload,
                $validatorData,
                $path->rawSegments(),
            );
        }

        return $payload;
    }

    /**
     * Restore one exact or wildcard path from the validator's data.
     *
     * @param list<null|array-key> $segments
     */
    private function restoreValueAtPath(
        mixed &$target,
        mixed $source,
        array $segments,
        int $offset = 0,
    ): void {
        if ($offset === count($segments)) {
            $target = is_array($source) ? ValidationData::decodeKeys($source) : $source;

            return;
        }

        if (! is_array($source)) {
            return;
        }

        $segment = $segments[$offset];

        if ($segment === null) {
            foreach ($source as $encodedKey => $value) {
                $key = is_int($encodedKey) ? $encodedKey : ValidationData::replacePlaceholderInString($encodedKey);

                if (! is_array($target)) {
                    $target = [];
                }

                if (! array_key_exists($key, $target)) {
                    $target[$key] = [];
                }

                $this->restoreValueAtPath(
                    $target[$key],
                    $value,
                    $segments,
                    $offset + 1,
                );
            }

            return;
        }

        $encodedSegment = ValidationData::encodeKey($segment);

        if (! array_key_exists($encodedSegment, $source)) {
            return;
        }

        $value = $source[$encodedSegment];
        $nextOffset = $offset + 1;

        // A failed descent must not materialize an empty container in the validated payload.
        if ($nextOffset !== count($segments) && ! is_array($value)) {
            return;
        }

        if (! is_array($target)) {
            $target = [];
        }

        if (! array_key_exists($segment, $target)) {
            $target[$segment] = [];
        }

        $this->restoreValueAtPath(
            $target[$segment],
            $value,
            $segments,
            $nextOffset,
        );
    }
}
