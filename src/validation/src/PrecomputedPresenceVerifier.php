<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Support\Number;

/**
 * Return database-proven presence facts from batched queries.
 *
 * Lookups and fallback memoization are scoped to one validation execution and
 * keyed by the complete database query shape. Unknown probes delegate to the
 * original verifier, preserving ordinary validation semantics.
 */
final class PrecomputedPresenceVerifier implements PresenceVerifierInterface
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

    /**
     * Create an execution-local lookup around the ordinary presence verifier.
     */
    public function __construct(
        private readonly PresenceVerifierInterface $fallback,
    ) {
    }

    /**
     * Build a key for the complete database query shape.
     */
    public static function lookupKey(PresenceQuery $query): ?string
    {
        $conditions = $query->conditions->cacheData();

        if ($conditions === null) {
            return null;
        }

        $shape = [
            'connection' => $query->connection,
            'collection' => $query->collection,
            'column' => $query->column,
            'conditions' => $conditions,
        ];

        if ($query->excludeId !== null) {
            $shape['exclusion'] = [$query->idColumn ?: 'id', $query->excludeId];
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
     */
    public function getCount(PresenceQuery $query, mixed $value): int
    {
        $lookupKey = self::lookupKey($query);

        if ($lookupKey === null || ! isset($this->lookups[$lookupKey])) {
            return $this->fallback->getCount($query, $value);
        }

        $bindingKey = self::bindingKey($value);

        if ($bindingKey === null) {
            return $this->fallback->getCount($query, $value);
        }

        $lookup = $this->lookups[$lookupKey];

        if (isset($lookup['exactHits'][$bindingKey]) || isset($lookup['knownPresent'][$bindingKey])) {
            return 1;
        }

        if (isset($lookup['provenAbsent'][$bindingKey])) {
            return 0;
        }

        return $this->fallbackCounts[$lookupKey][$bindingKey]
            ??= $this->fallback->getCount($query, $value);
    }

    /**
     * Count the number of objects in a collection with the given values.
     *
     * @param array<int|string, mixed> $values
     */
    public function getMultiCount(PresenceQuery $query, array $values): int
    {
        $lookupKey = self::lookupKey($query);

        if ($lookupKey === null || ! isset($this->lookups[$lookupKey])) {
            return $this->fallback->getMultiCount($query, $values);
        }

        $bindingKeys = [];

        foreach ($values as $value) {
            $bindingKey = self::bindingKey($value);

            if ($bindingKey === null) {
                return $this->fallback->getMultiCount($query, $values);
            }

            $bindingKeys[$bindingKey] = true;
        }

        $lookup = $this->lookups[$lookupKey];
        $distinctValueCount = count($bindingKeys);
        $presentCount = 0;

        foreach ($bindingKeys as $bindingKey => $_) {
            if (isset($lookup['exactHits'][$bindingKey])) {
                if (! $lookup['stageOneSingleChunk']) {
                    return $this->fallback->getMultiCount($query, $values);
                }

                ++$presentCount;
                continue;
            }

            if (isset($lookup['knownPresent'][$bindingKey])) {
                if ($distinctValueCount !== 1) {
                    return $this->fallback->getMultiCount($query, $values);
                }

                ++$presentCount;
                continue;
            }

            if (! isset($lookup['provenAbsent'][$bindingKey])) {
                return $this->fallback->getMultiCount($query, $values);
            }
        }

        return $presentCount;
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
     * Presence queries bind integers as strings, so equal string forms share a key.
     * The prefix prevents PHP from coercing numeric-string array keys to integers.
     */
    public static function bindingKey(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        return 's' . (is_float($value) ? Number::toString($value) : $value);
    }
}
