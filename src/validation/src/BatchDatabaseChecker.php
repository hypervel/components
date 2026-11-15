<?php

declare(strict_types=1);

namespace Hypervel\Validation;

/**
 * Query database-presence candidates in groups.
 *
 * The validator owns rule interpretation and ordered candidate selection. This
 * class turns each complete query shape into database-proven execution-local
 * facts consumed by PrecomputedPresenceVerifier.
 */
final class BatchDatabaseChecker
{
    private const int CHUNK_SIZE = 1000;

    /**
     * Build a precomputed verifier from grouped candidates.
     *
     * @param array<string, array{query: PresenceQuery, values: list<mixed>}> $groups
     */
    public static function buildVerifier(array $groups, DatabasePresenceVerifier $presenceVerifier): ?PrecomputedPresenceVerifier
    {
        $verifier = new PrecomputedPresenceVerifier($presenceVerifier);

        foreach ($groups as $lookupKey => $group) {
            self::registerLookup($verifier, $presenceVerifier, $lookupKey, $group['query'], $group['values']);
        }

        return $verifier->hasLookups() ? $verifier : null;
    }

    /**
     * Query and register database-proven facts for one query shape.
     *
     * @param list<mixed> $values
     */
    private static function registerLookup(
        PrecomputedPresenceVerifier $verifier,
        DatabasePresenceVerifier $presenceVerifier,
        string $lookupKey,
        PresenceQuery $query,
        array $values,
    ): void {
        $representativeValues = self::normalizeCandidates($values);

        if ($representativeValues === []) {
            return;
        }

        $stageOneSingleChunk = count($representativeValues) <= self::CHUNK_SIZE;
        $stageOneValues = self::queryValues(
            $presenceVerifier,
            $query,
            array_values($representativeValues),
        );
        $exactHits = [];
        $knownPresent = [];
        $provenAbsent = [];

        foreach ($stageOneValues as $value) {
            $bindingKey = PrecomputedPresenceVerifier::bindingKey($value);

            // A returned equal string form proves the normalized presence binding matched.
            if ($bindingKey !== null && isset($representativeValues[$bindingKey])) {
                $exactHits[$bindingKey] = true;
            }
        }

        $misses = array_diff_key($representativeValues, $exactHits);

        // An isolation query is useful only after exact hits shrink the original candidate set.
        if ($stageOneValues === []) {
            $provenAbsent = array_fill_keys(array_keys($representativeValues), true);
        } elseif (count($representativeValues) === 1 && $exactHits === []) {
            $knownPresent[array_key_first($representativeValues)] = true;
        } elseif ($exactHits !== [] && $misses !== []) {
            $stageTwoValues = self::queryValues($presenceVerifier, $query, array_values($misses));

            if ($stageTwoValues === []) {
                $provenAbsent = array_fill_keys(array_keys($misses), true);
            } else {
                foreach ($stageTwoValues as $value) {
                    $bindingKey = PrecomputedPresenceVerifier::bindingKey($value);

                    if ($bindingKey !== null && isset($misses[$bindingKey])) {
                        $knownPresent[$bindingKey] = true;
                    }
                }

                if (count($misses) === 1) {
                    $knownPresent[array_key_first($misses)] = true;
                }
            }
        }

        $verifier->addLookup(
            $lookupKey,
            $exactHits,
            $knownPresent,
            $provenAbsent,
            $stageOneSingleChunk,
        );
    }

    /**
     * Run chunked queries for one database query shape.
     *
     * @param list<float|int|string> $values
     * @return list<mixed>
     */
    private static function queryValues(
        DatabasePresenceVerifier $presenceVerifier,
        PresenceQuery $query,
        array $values,
    ): array {
        $results = [];

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            array_push($results, ...$presenceVerifier->getExistingValues($query, $chunk));
        }

        return $results;
    }

    /**
     * Normalize binding identities while retaining the first candidate value.
     *
     * An unsupported array item rejects only that concrete array candidate;
     * safe siblings in the same query-shape group remain batchable.
     *
     * @param list<mixed> $values
     * @return array<string, float|int|string>
     */
    private static function normalizeCandidates(array $values): array
    {
        $representativeValues = [];

        foreach ($values as $value) {
            $candidateValues = [];

            foreach (is_array($value) ? $value : [$value] as $item) {
                $bindingKey = PrecomputedPresenceVerifier::bindingKey($item);

                if ($bindingKey === null) {
                    continue 2;
                }

                $candidateValues[$bindingKey] ??= $item;
            }

            foreach ($candidateValues as $bindingKey => $rawValue) {
                $representativeValues[$bindingKey] ??= $rawValue;
            }
        }

        return $representativeValues;
    }
}
