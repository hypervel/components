<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

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
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed> $sourcePayload
     * @return array<array-key, mixed>
     */
    public function restorePreservedValues(array $payload, array $sourcePayload): array
    {
        foreach ($this->preservedPaths as $path) {
            $this->restoreValueAtPath(
                $payload,
                $sourcePayload,
                $path->rawSegments(),
            );
        }

        return $payload;
    }

    /**
     * Restore one exact or wildcard path from the source payload.
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
            $target = $source;

            return;
        }

        if (! is_array($source)) {
            return;
        }

        $segment = $segments[$offset];

        if ($segment === null) {
            foreach ($source as $key => $value) {
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

        if (! array_key_exists($segment, $source)) {
            return;
        }

        $value = $source[$segment];
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
