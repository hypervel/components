<?php

declare(strict_types=1);

namespace Hypervel\Validation;

/**
 * Presence verifier that returns pre-computed results from batched queries.
 *
 * Used by BatchDatabaseChecker to replace per-item DB queries with lookup-set
 * checks. Original Exists/Unique rule objects stay in place, so the full
 * message resolution pipeline works unchanged.
 *
 * Results are scoped by table+column. Falls back to the provided verifier
 * for lookups that weren't pre-computed (rules with closure callbacks).
 */
final class PrecomputedPresenceVerifier implements DatabasePresenceVerifierInterface
{
    /** @var array<string, array<string, true>> Keyed by "table:column", values are string-cast flip maps. */
    private array $lookups = [];

    public function __construct(
        private readonly ?PresenceVerifierInterface $fallback = null,
    ) {
    }

    /**
     * Register pre-computed values for a table+column pair.
     *
     * Values are cast to strings and stored as a flip map for O(1) isset()
     * lookups. String cast matches database implicit type coercion behavior.
     *
     * @param array<int, mixed> $values values that exist in the database
     */
    public function addLookup(string $table, string $column, array $values): void
    {
        $map = [];

        foreach ($values as $v) {
            if ($v !== null) {
                $map[(string) $v] = true;
            }
        }

        $this->lookups[$table . ':' . $column] = $map;
    }

    /**
     * Count the number of objects in a collection having the given value.
     *
     * Returns 1 if the value exists in the precomputed lookup, 0 otherwise.
     * Falls back to the original verifier when no lookup was registered for
     * this table:column pair.
     *
     * @param array<mixed> $extra
     */
    public function getCount(string $collection, string $column, mixed $value, int|string|null $excludeId = null, ?string $idColumn = null, array $extra = []): int
    {
        $key = $collection . ':' . $column;

        if (! isset($this->lookups[$key])) {
            return $this->fallback?->getCount($collection, $column, $value, $excludeId, $idColumn, $extra) ?? 0;
        }

        return isset($this->lookups[$key][is_scalar($value) ? (string) $value : '']) ? 1 : 0;
    }

    /**
     * Count the number of objects in a collection with the given values.
     *
     * Uses distinct counting to match DatabasePresenceVerifier's
     * ->distinct()->count($column) semantics. Duplicate input values
     * are counted only once.
     *
     * @param array<int|string, mixed> $values
     * @param array<mixed> $extra
     */
    public function getMultiCount(string $collection, string $column, array $values, array $extra = []): int
    {
        $key = $collection . ':' . $column;

        if (! isset($this->lookups[$key])) {
            return $this->fallback?->getMultiCount($collection, $column, $values, $extra) ?? 0;
        }

        $count = 0;
        $lookup = $this->lookups[$key];
        $seen = [];

        foreach ($values as $val) {
            if (! is_scalar($val)) {
                continue;
            }

            $stringVal = (string) $val;

            if (! isset($seen[$stringVal]) && isset($lookup[$stringVal])) {
                ++$count;
                $seen[$stringVal] = true;
            }
        }

        return $count;
    }

    /**
     * Set the connection to be used.
     *
     * Delegates to the fallback verifier if it supports connections.
     */
    public function setConnection(?string $connection): void
    {
        if ($this->fallback instanceof DatabasePresenceVerifierInterface) {
            $this->fallback->setConnection($connection);
        }
    }

    /**
     * Determine if any lookups have been registered.
     */
    public function hasLookups(): bool
    {
        return $this->lookups !== [];
    }
}
