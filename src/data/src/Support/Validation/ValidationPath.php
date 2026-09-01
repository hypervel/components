<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

use Stringable;

class ValidationPath implements Stringable
{
    /**
     * Create a validation path.
     *
     * @param list<array-key|null> $path
     */
    public function __construct(
        protected readonly array $path = [],
    ) {
    }

    /**
     * Create a validation path from dot notation.
     */
    public static function create(?string $path = null): self
    {
        if ($path === null) {
            return new self;
        }

        return new self(self::parseDotPath($path));
    }

    /**
     * Append a property or collection segment.
     */
    public function property(string|int $property): self
    {
        $newPath = $this->path;

        array_push(
            $newPath,
            ...(is_int($property) ? [$property] : self::parseDotPath($property)),
        );

        return new self($newPath);
    }

    /**
     * Append one raw collection key.
     */
    public function item(string|int $key): self
    {
        $newPath = $this->path;

        $newPath[] = $key;

        return new self($newPath);
    }

    /**
     * Append a collection wildcard.
     */
    public function wildcard(): self
    {
        $newPath = $this->path;

        $newPath[] = null;

        return new self($newPath);
    }

    /**
     * Determine if this is the root path.
     */
    public function isRoot(): bool
    {
        return $this->path === [];
    }

    /**
     * Determine if this path equals another path.
     */
    public function equals(string|ValidationPath $other): bool
    {
        $otherPath = $other instanceof ValidationPath
            ? $other->path
            : self::parseDotPath($other);

        return $this->path === $otherPath;
    }

    /**
     * Get the path segments.
     *
     * @return list<array-key|'*'>
     */
    public function segments(): array
    {
        return array_map(
            fn (string|int|null $segment): string|int => $segment ?? '*',
            $this->path,
        );
    }

    /**
     * Get structural segments with wildcards represented by null.
     *
     * @return list<array-key|null>
     */
    public function rawSegments(): array
    {
        return $this->path;
    }

    /**
     * Get the path in dot notation.
     */
    public function get(): string
    {
        return implode('.', array_map(
            fn (string|int|null $segment): string => match (true) {
                $segment === null => '*',
                is_int($segment) => (string) $segment,
                default => str_replace(['.', '*'], ['\\.', '\\*'], $segment),
            },
            $this->path,
        ));
    }

    /**
     * Get the string form of the path.
     */
    public function __toString(): string
    {
        return $this->get();
    }

    /**
     * Determine if this path contains a wildcard.
     */
    public function containsWildcards(): bool
    {
        return in_array(null, $this->path, true);
    }

    /**
     * Get the concrete paths matching this wildcard path.
     *
     * @return array<array-key, self>
     */
    public function matchingWildcardPayloadValidationPaths(array $fullPayload): array
    {
        return $this->expandWildcardPath($this->path, $fullPayload);
    }

    /**
     * Recursively expand wildcard segments against a payload.
     *
     * @param list<array-key|null> $remainingSegments
     * @param list<array-key> $resolvedSegments
     * @return list<self>
     */
    protected function expandWildcardPath(
        array $remainingSegments,
        mixed $payload,
        array $resolvedSegments = [],
    ): array
    {
        if ($remainingSegments === []) {
            return [new self($resolvedSegments)];
        }

        $segment = array_shift($remainingSegments);

        if ($segment === null) {
            if (! is_array($payload)) {
                return [];
            }

            $results = [];

            foreach (array_keys($payload) as $key) {
                array_push($results, ...$this->expandWildcardPath(
                    $remainingSegments,
                    $payload[$key],
                    [...$resolvedSegments, $key],
                ));
            }

            return $results;
        }

        return $this->expandWildcardPath(
            $remainingSegments,
            is_array($payload) && array_key_exists($segment, $payload)
                ? $payload[$segment]
                : null,
            [...$resolvedSegments, $segment],
        );
    }

    /**
     * Parse Validator dot notation into structural segments.
     *
     * @return list<array-key|null>
     */
    protected static function parseDotPath(string $path): array
    {
        $segments = preg_split('/(?<!\\\\)\./', $path);

        return array_map(
            static function (string $segment): string|int|null {
                if ($segment === '*') {
                    return null;
                }

                $segment = str_replace(['\\.', '\\*'], ['.', '*'], $segment);
                $integer = filter_var($segment, FILTER_VALIDATE_INT);

                return $integer !== false && (string) $integer === $segment
                    ? $integer
                    : $segment;
            },
            $segments,
        );
    }
}
