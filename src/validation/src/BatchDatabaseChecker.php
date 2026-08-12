<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Stringable;

/**
 * Execute batched database queries for wildcard exists/unique validation.
 *
 * This is a thin query layer — rule interpretation and metadata extraction
 * happen on the Validator (using its own parseTable, getQueryColumn, etc.).
 * This class only receives pre-built groups and runs the batch queries.
 *
 * Builds a PrecomputedPresenceVerifier that can be set on the validator,
 * keeping original rule objects intact for correct error message resolution.
 */
final class BatchDatabaseChecker
{
    private const CHUNK_SIZE = 1000;

    /**
     * Build a PrecomputedPresenceVerifier from grouped rules.
     *
     * @param array<string, array{meta: array<string, mixed>, values: list<mixed>}> $groups
     * @param array<string, true> $unsafeTableColumns table:column pairs that must not be
     *                                                precomputed because other rules use the
     *                                                same pair with a different query shape
     */
    public static function buildVerifier(array $groups, DatabasePresenceVerifier $presenceVerifier, array $unsafeTableColumns = []): ?PrecomputedPresenceVerifier
    {
        $verifier = new PrecomputedPresenceVerifier($presenceVerifier);

        self::registerLookups($verifier, $presenceVerifier, $groups, $unsafeTableColumns);

        return $verifier->hasLookups() ? $verifier : null;
    }

    /**
     * Batch-query and register lookups on a verifier for grouped rules.
     *
     * If multiple groups collapse to the same table:column (different query
     * shapes for the same target), none are registered — they all fall back
     * to the real verifier. The PrecomputedPresenceVerifier API only keys
     * by table:column, so it cannot distinguish between different query shapes.
     *
     * @param array<string, true> $unsafeTableColumns table:column pairs blocked from precomputing
     */
    private static function registerLookups(
        PrecomputedPresenceVerifier $verifier,
        DatabasePresenceVerifier $presenceVerifier,
        array $groups,
        array $unsafeTableColumns = [],
    ): void {
        // Detect table:column collisions — multiple query shapes targeting the
        // same table:column cannot be safely stored in the verifier.
        $tableColumnCounts = [];
        foreach ($groups as $group) {
            $verifierKey = $group['meta']['table'] . ':' . $group['meta']['column'];
            $tableColumnCounts[$verifierKey] = ($tableColumnCounts[$verifierKey] ?? 0) + 1;
        }

        foreach ($groups as $group) {
            $values = self::uniqueStringValues($group['values']);

            if ($values === null) {
                continue;
            }

            if ($values === []) {
                continue;
            }

            $meta = $group['meta'];
            $verifierKey = $meta['table'] . ':' . $meta['column'];

            // Skip if multiple batch groups target the same table:column,
            // or if non-batched rules also use this table:column pair.
            if ($tableColumnCounts[$verifierKey] > 1 || isset($unsafeTableColumns[$verifierKey])) {
                continue;
            }

            $fetched = self::queryValues(
                $presenceVerifier,
                $meta['connection'],
                $meta['table'],
                $meta['column'],
                $values,
                $meta['wheres'],
                $meta['type'] === 'unique' ? $meta['ignore'] : null,
                $meta['type'] === 'unique' ? $meta['idColumn'] : 'id',
            );

            $verifier->addLookup($meta['table'], $meta['column'], $fetched);
        }
    }

    /**
     * Run the batched whereIn query and return matching values.
     *
     * Replays scalar where conditions matching DatabasePresenceVerifier::addWhere()
     * behavior. Uses write PDO to match the presence verifier's behavior.
     *
     * @param array<int, mixed> $values
     * @param array<string, mixed> $wheres Key => value pairs (column => value)
     * @return array<int, mixed>
     */
    private static function queryValues(
        DatabasePresenceVerifier $presenceVerifier,
        ?string $connection,
        string $table,
        string $column,
        array $values,
        array $wheres,
        mixed $ignore = null,
        string $idColumn = 'id',
    ): array {
        $results = [];

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            array_push($results, ...$presenceVerifier->getExistingValues(
                $table,
                $column,
                $chunk,
                $connection,
                $ignore,
                $idColumn,
                $wheres,
            ));
        }

        return $results;
    }

    /**
     * Deduplicate and cast values to strings for batch queries.
     *
     * @param array<mixed> $values
     * @return null|list<string>
     */
    private static function uniqueStringValues(array $values): ?array
    {
        $normalized = [];

        foreach ($values as $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (! is_scalar($item) && ! $item instanceof Stringable) {
                    return null;
                }

                $normalized[] = (string) $item;
            }
        }

        return array_values(array_unique($normalized, SORT_STRING));
    }
}
