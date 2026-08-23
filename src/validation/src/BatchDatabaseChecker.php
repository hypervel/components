<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Stringable;

/**
 * Query wildcard database-presence candidates in groups.
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
     * @param array<string, array{
     *     meta: array{
     *         connection: ?string,
     *         table: string,
     *         column: string,
     *         wheres: array<string, mixed>,
     *         ignore: null|int|string,
     *         idColumn: ?string
     *     },
     *     values: list<mixed>
     * }> $groups
     */
    public static function buildVerifier(array $groups, DatabasePresenceVerifier $presenceVerifier): ?PrecomputedPresenceVerifier
    {
        $verifier = new PrecomputedPresenceVerifier($presenceVerifier);

        foreach ($groups as $lookupKey => $group) {
            self::registerLookup($verifier, $presenceVerifier, $lookupKey, $group['meta'], $group['values']);
        }

        return $verifier->hasLookups() ? $verifier : null;
    }

    /**
     * Query and register database-proven facts for one query shape.
     *
     * @param array{
     *     connection: ?string,
     *     table: string,
     *     column: string,
     *     wheres: array<string, mixed>,
     *     ignore: null|int|string,
     *     idColumn: ?string
     * } $meta
     * @param list<mixed> $values
     */
    private static function registerLookup(
        PrecomputedPresenceVerifier $verifier,
        DatabasePresenceVerifier $presenceVerifier,
        string $lookupKey,
        array $meta,
        array $values,
    ): void {
        $representativeValues = self::normalizeCandidates($values);

        if ($representativeValues === []) {
            return;
        }

        $stageOneSingleChunk = count($representativeValues) <= self::CHUNK_SIZE;
        $stageOneValues = self::queryValues(
            $presenceVerifier,
            $meta,
            array_values($representativeValues),
        );
        $comparisonIndex = [];

        foreach (array_keys($representativeValues) as $bindingKey) {
            $comparisonIndex[substr($bindingKey, 1)][] = $bindingKey;
        }

        $exactHits = [];
        $knownPresent = [];
        $provenAbsent = [];

        foreach ($stageOneValues as $value) {
            $normalizedValue = PrecomputedPresenceVerifier::normalizeValue($value);

            if ($normalizedValue === null) {
                continue;
            }

            // Query success proves each retained PDO binding was accepted. A returned
            // equal string form is therefore exact for every matching submitted binding.
            foreach ($comparisonIndex[$normalizedValue] ?? [] as $bindingKey) {
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
            $stageTwoValues = self::queryValues($presenceVerifier, $meta, array_values($misses));

            if ($stageTwoValues === []) {
                $provenAbsent = array_fill_keys(array_keys($misses), true);
            } else {
                foreach ($stageTwoValues as $value) {
                    $normalizedValue = PrecomputedPresenceVerifier::normalizeValue($value);

                    if ($normalizedValue === null) {
                        continue;
                    }

                    foreach ($comparisonIndex[$normalizedValue] ?? [] as $bindingKey) {
                        if (isset($misses[$bindingKey])) {
                            $knownPresent[$bindingKey] = true;
                        }
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
     * @param array{
     *     connection: ?string,
     *     table: string,
     *     column: string,
     *     wheres: array<string, mixed>,
     *     ignore: null|int|string,
     *     idColumn: ?string
     * } $meta
     * @param list<float|int|string> $values
     * @return list<mixed>
     */
    private static function queryValues(
        DatabasePresenceVerifier $presenceVerifier,
        array $meta,
        array $values,
    ): array {
        $results = [];

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            array_push($results, ...$presenceVerifier->getExistingValues(
                $meta['table'],
                $meta['column'],
                $chunk,
                $meta['connection'],
                $meta['ignore'],
                $meta['idColumn'],
                $meta['wheres'],
            ));
        }

        return $results;
    }

    /**
     * Normalize candidates while retaining the first raw SQL binding.
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

                $rawValue = $item instanceof Stringable ? substr($bindingKey, 1) : $item;
                $candidateValues[$bindingKey] ??= $rawValue;
            }

            foreach ($candidateValues as $bindingKey => $rawValue) {
                $representativeValues[$bindingKey] ??= $rawValue;
            }
        }

        return $representativeValues;
    }
}
