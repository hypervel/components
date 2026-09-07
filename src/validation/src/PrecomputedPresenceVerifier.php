<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;

/**
 * Return database-proven presence facts from batched queries.
 *
 * Lookups and fallback memoization are scoped to one validation execution and
 * keyed by the complete database query shape. Unknown probes delegate to the
 * original verifier, preserving ordinary validation semantics.
 */
final class PrecomputedPresenceVerifier implements DatabasePresenceVerifierInterface
{
    /**
     * @var array<string, array{
     *     exactHits: array<string, true>,
     *     knownPresent: array<string, true>,
     *     provenAbsent: array<string, true>,
     *     stageOneSingleChunk: bool
     * }>
     */
    private array $lookups = [];

    /** @var array<string, array<string, int>> */
    private array $fallbackCounts = [];

    private ?string $connection = null;

    public function __construct(
        private readonly DatabasePresenceVerifierInterface $fallback,
    ) {
    }

    /**
     * Build a key for the complete database query shape.
     */
    public static function lookupKey(
        ?string $connection,
        string $collection,
        string $column,
        int|string|null $excludeId = null,
        ?string $idColumn = null,
        array $extra = [],
    ): ?string {
        $conditions = [];

        foreach ($extra as $key => $value) {
            if ($value instanceof Closure
                || (! is_scalar($value) && $value !== null)
            ) {
                return null;
            }

            $conditions[] = [(string) $key, (string) $value];
        }

        $shape = [
            'connection' => $connection,
            'collection' => $collection,
            'column' => $column,
            'conditions' => $conditions,
        ];

        if ($excludeId !== null && $excludeId !== 'NULL') {
            $shape['exclusion'] = [$idColumn ?: 'id', $excludeId];
        }

        return serialize($shape);
    }

    /**
     * Register database-proven facts for one query shape.
     *
     * @param array<string, true> $exactHits
     * @param array<string, true> $knownPresent
     * @param array<string, true> $provenAbsent
     */
    public function addLookup(
        string $lookupKey,
        array $exactHits,
        array $knownPresent,
        array $provenAbsent,
        bool $stageOneSingleChunk,
    ): void {
        $this->lookups[$lookupKey] = [
            'exactHits' => $exactHits,
            'knownPresent' => $knownPresent,
            'provenAbsent' => $provenAbsent,
            'stageOneSingleChunk' => $stageOneSingleChunk,
        ];
    }

    /**
     * Count the number of objects in a collection having the given value.
     *
     * @param array<mixed> $extra
     */
    public function getCount(string $collection, string $column, mixed $value, int|string|null $excludeId = null, ?string $idColumn = null, array $extra = []): int
    {
        $lookupKey = self::lookupKey(
            $this->connection,
            $collection,
            $column,
            $excludeId,
            $idColumn,
            $extra,
        );

        if ($lookupKey === null || ! isset($this->lookups[$lookupKey])) {
            return $this->fallback->getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
        }

        $bindingKey = self::bindingKey($value);

        if ($bindingKey === null) {
            return $this->fallback->getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
        }

        $lookup = $this->lookups[$lookupKey];

        if (isset($lookup['exactHits'][$bindingKey]) || isset($lookup['knownPresent'][$bindingKey])) {
            return 1;
        }

        if (isset($lookup['provenAbsent'][$bindingKey])) {
            return 0;
        }

        return $this->fallbackCounts[$lookupKey][$bindingKey]
            ??= $this->fallback->getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
    }

    /**
     * Count the number of objects in a collection with the given values.
     *
     * @param array<int|string, mixed> $values
     * @param array<mixed> $extra
     */
    public function getMultiCount(string $collection, string $column, array $values, array $extra = []): int
    {
        $lookupKey = self::lookupKey($this->connection, $collection, $column, extra: $extra);

        if ($lookupKey === null || ! isset($this->lookups[$lookupKey])) {
            return $this->fallback->getMultiCount($collection, $column, $values, $extra);
        }

        $bindingValues = [];

        foreach ($values as $value) {
            $bindingKey = self::bindingKey($value);

            if ($bindingKey === null) {
                return $this->fallback->getMultiCount($collection, $column, $values, $extra);
            }

            $bindingValues[$bindingKey] = substr($bindingKey, 1);
        }

        $lookup = $this->lookups[$lookupKey];
        $distinctValueCount = count(array_unique($bindingValues, SORT_STRING));
        $presentValues = [];

        foreach ($bindingValues as $bindingKey => $normalizedValue) {
            if (isset($lookup['exactHits'][$bindingKey])) {
                if (! $lookup['stageOneSingleChunk']) {
                    return $this->fallback->getMultiCount($collection, $column, $values, $extra);
                }

                $presentValues[$normalizedValue] = true;
                continue;
            }

            if (isset($lookup['knownPresent'][$bindingKey])) {
                if ($distinctValueCount !== 1) {
                    return $this->fallback->getMultiCount($collection, $column, $values, $extra);
                }

                $presentValues[$normalizedValue] = true;
                continue;
            }

            if (! isset($lookup['provenAbsent'][$bindingKey])) {
                return $this->fallback->getMultiCount($collection, $column, $values, $extra);
            }
        }

        return count($presentValues);
    }

    /**
     * Set the connection to be used.
     */
    public function setConnection(?string $connection): void
    {
        $this->connection = $connection;
        $this->fallback->setConnection($connection);
    }

    /**
     * Determine if any lookups have been registered.
     */
    public function hasLookups(): bool
    {
        return $this->lookups !== [];
    }

    /**
     * Build a key for the PDO binding identity of a supported presence value.
     *
     * The one-character prefix preserves integer/string PDO binding identity
     * and prevents PHP from coercing numeric-string array keys to integers.
     */
    public static function bindingKey(mixed $value): ?string
    {
        $normalizedValue = self::normalizeValue($value);

        return $normalizedValue === null
            ? null
            : (is_int($value) ? 'i' : 's') . $normalizedValue;
    }

    /**
     * Normalize a supported presence value for lookup comparisons.
     */
    public static function normalizeValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        return (string) $value;
    }
}
