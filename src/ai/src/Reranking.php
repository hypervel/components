<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Closure;
use Hypervel\Ai\Gateway\FakeRerankingGateway;
use Hypervel\Ai\PendingResponses\PendingReranking;
use Hypervel\Support\Collection;
use InvalidArgumentException;

class Reranking
{
    /**
     * Create a new pending reranking for the given documents.
     *
     * @param array<int, string>|Collection<int, string> $documents
     *
     * @throws InvalidArgumentException if the given documents are not a list, are empty, are not strings, or contain only blank strings
     */
    public static function of(Collection|array $documents): PendingReranking
    {
        if ($documents instanceof Collection) {
            $documents = $documents->values()->all();
        }

        return new PendingReranking($documents);
    }

    /**
     * Fake reranking operations.
     *
     * Tests only. The fake gateway is shared across requests in the worker.
     */
    public static function fake(Closure|array $responses = []): FakeRerankingGateway
    {
        return Ai::fakeReranking($responses);
    }

    /**
     * Assert that a reranking was performed matching a given truth test.
     */
    public static function assertReranked(Closure $callback): void
    {
        Ai::assertReranked($callback);
    }

    /**
     * Assert that a reranking was not performed matching a given truth test.
     */
    public static function assertNotReranked(Closure $callback): void
    {
        Ai::assertNotReranked($callback);
    }

    /**
     * Assert that no rerankings were performed.
     */
    public static function assertNothingReranked(): void
    {
        Ai::assertNothingReranked();
    }

    /**
     * Determine if reranking is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::rerankingIsFaked();
    }
}
