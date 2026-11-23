<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Concerns;

use Hypervel\Ai\Exceptions\AiException;

trait ParsesIndexedEmbeddings
{
    /**
     * Return embedding values in input order without accepting ambiguous indices.
     *
     * @param array<array-key, mixed> $records
     * @return list<mixed>
     */
    protected function parseIndexedEmbeddings(array $records, int $expectedCount, string $providerName): array
    {
        if (count($records) !== $expectedCount) {
            throw new AiException("{$providerName} Error: [invalid_response] The response did not contain the expected number of embeddings.");
        }

        $ordered = array_fill(0, $expectedCount, null);

        foreach ($records as $position => $record) {
            // Some compatible servers omit indices; retain their response order.
            $index = is_array($record) ? ($record['index'] ?? $position) : null;

            if (! is_int($index) || $index < 0 || $index >= $expectedCount || $ordered[$index] !== null) {
                throw new AiException("{$providerName} Error: [invalid_response] The response contained invalid embedding indices.");
            }

            $ordered[$index] = $record;
        }

        // Matching counts and unique in-range indices ensure every slot contains a record.
        /** @var list<array<string, mixed>> $ordered */
        return array_map(static fn (array $record): mixed => $record['embedding'] ?? null, $ordered);
    }
}
