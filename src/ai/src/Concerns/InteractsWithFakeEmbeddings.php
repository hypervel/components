<?php

declare(strict_types=1);

namespace Hypervel\Ai\Concerns;

use Closure;
use Hypervel\Ai\Gateway\FakeEmbeddingGateway;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Ai\Prompts\QueuedEmbeddingsPrompt;
use Hypervel\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeEmbeddings
{
    /**
     * The fake embedding gateway instance.
     */
    protected ?FakeEmbeddingGateway $fakeEmbeddingGateway = null;

    /**
     * All of the recorded embeddings generations.
     */
    protected array $recordedEmbeddingsGenerations = [];

    /**
     * All of the recorded embeddings generations that were queued.
     */
    protected array $recordedQueuedEmbeddingsGenerations = [];

    /**
     * Fake embeddings generation.
     *
     * Tests only. The fake gateway is shared by all requests in the worker.
     */
    public function fakeEmbeddings(Closure|array $responses = []): FakeEmbeddingGateway
    {
        return $this->fakeEmbeddingGateway = new FakeEmbeddingGateway($responses);
    }

    /**
     * Record an embeddings generation.
     *
     * Tests only. Recorded prompts remain on the worker-shared manager.
     */
    public function recordEmbeddingsGeneration(EmbeddingsPrompt|QueuedEmbeddingsPrompt $prompt): self
    {
        if ($prompt instanceof QueuedEmbeddingsPrompt) {
            $this->recordedQueuedEmbeddingsGenerations[] = $prompt;
        } else {
            $this->recordedEmbeddingsGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that embeddings were generated matching a given truth test.
     */
    public function assertEmbeddingsGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedEmbeddingsGenerations))->contains(fn (EmbeddingsPrompt $prompt): mixed => $callback($prompt)),
            'An expected embeddings generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that embeddings were not generated matching a given truth test.
     */
    public function assertEmbeddingsNotGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedEmbeddingsGenerations))->doesntContain(fn (EmbeddingsPrompt $prompt): mixed => $callback($prompt)),
            'An unexpected embeddings generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no embeddings were generated.
     */
    public function assertNoEmbeddingsGenerated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedEmbeddingsGenerations,
            'Unexpected embeddings generations were recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued embeddings generation was recorded matching a given truth test.
     */
    public function assertEmbeddingsQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedEmbeddingsGenerations))->contains(fn (QueuedEmbeddingsPrompt $prompt): mixed => $callback($prompt)),
            'An expected queued embeddings generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued embeddings generation was not recorded matching a given truth test.
     */
    public function assertEmbeddingsNotQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedEmbeddingsGenerations))->doesntContain(fn (QueuedEmbeddingsPrompt $prompt): mixed => $callback($prompt)),
            'An unexpected queued embeddings generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no queued embeddings generations were recorded.
     */
    public function assertNoEmbeddingsQueued(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedEmbeddingsGenerations,
            'Unexpected queued embeddings generations were recorded.'
        );

        return $this;
    }

    /**
     * Determine if embeddings generation is faked.
     *
     * @phpstan-assert-if-true FakeEmbeddingGateway $this->fakeEmbeddingGateway()
     */
    public function embeddingsAreFaked(): bool
    {
        return $this->fakeEmbeddingGateway !== null;
    }

    /**
     * Get the fake embedding gateway.
     */
    public function fakeEmbeddingGateway(): ?FakeEmbeddingGateway
    {
        return $this->fakeEmbeddingGateway;
    }
}
