<?php

declare(strict_types=1);

namespace Hypervel\Ai\Concerns;

use Closure;
use Hypervel\Ai\Gateway\FakeRerankingGateway;
use Hypervel\Ai\Prompts\RerankingPrompt;
use Hypervel\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeReranking
{
    /**
     * The fake reranking gateway instance.
     */
    protected ?FakeRerankingGateway $fakeRerankingGateway = null;

    /**
     * All of the recorded rerankings.
     */
    protected array $recordedRerankings = [];

    /**
     * Fake reranking operations.
     *
     * Tests only. The fake gateway is shared by all requests in the worker.
     */
    public function fakeReranking(Closure|array $responses = []): FakeRerankingGateway
    {
        return $this->fakeRerankingGateway = new FakeRerankingGateway($responses);
    }

    /**
     * Record a reranking.
     *
     * Tests only. Recorded prompts remain on the worker-shared manager.
     */
    public function recordReranking(RerankingPrompt $prompt): self
    {
        $this->recordedRerankings[] = $prompt;

        return $this;
    }

    /**
     * Assert that a reranking was performed matching a given truth test.
     */
    public function assertReranked(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedRerankings))->contains(fn (RerankingPrompt $prompt): mixed => $callback($prompt)),
            'An expected reranking was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a reranking was not performed matching a given truth test.
     */
    public function assertNotReranked(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedRerankings))->doesntContain(fn (RerankingPrompt $prompt): mixed => $callback($prompt)),
            'An unexpected reranking was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no rerankings were performed.
     */
    public function assertNothingReranked(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedRerankings,
            'Unexpected rerankings were recorded.'
        );

        return $this;
    }

    /**
     * Determine if reranking is faked.
     *
     * @phpstan-assert-if-true FakeRerankingGateway $this->fakeRerankingGateway()
     */
    public function rerankingIsFaked(): bool
    {
        return $this->fakeRerankingGateway !== null;
    }

    /**
     * Get the fake reranking gateway.
     */
    public function fakeRerankingGateway(): ?FakeRerankingGateway
    {
        return $this->fakeRerankingGateway;
    }
}
