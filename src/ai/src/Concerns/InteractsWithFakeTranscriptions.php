<?php

declare(strict_types=1);

namespace Hypervel\Ai\Concerns;

use Closure;
use Hypervel\Ai\Gateway\FakeTranscriptionGateway;
use Hypervel\Ai\Prompts\QueuedTranscriptionPrompt;
use Hypervel\Ai\Prompts\TranscriptionPrompt;
use Hypervel\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeTranscriptions
{
    /**
     * The fake transcription gateway instance.
     */
    protected ?FakeTranscriptionGateway $fakeTranscriptionGateway = null;

    /**
     * All of the recorded transcription generations.
     */
    protected array $recordedTranscriptionGenerations = [];

    /**
     * All of the recorded transcription generations that were queued.
     */
    protected array $recordedQueuedTranscriptionGenerations = [];

    /**
     * Fake transcription generation.
     *
     * Tests only. The fake gateway is shared by all requests in the worker.
     */
    public function fakeTranscriptions(Closure|array $responses = []): FakeTranscriptionGateway
    {
        return $this->fakeTranscriptionGateway = new FakeTranscriptionGateway($responses);
    }

    /**
     * Record a transcription generation.
     *
     * Tests only. Recorded prompts remain on the worker-shared manager.
     */
    public function recordTranscriptionGeneration(TranscriptionPrompt|QueuedTranscriptionPrompt $prompt): self
    {
        if ($prompt instanceof QueuedTranscriptionPrompt) {
            $this->recordedQueuedTranscriptionGenerations[] = $prompt;
        } else {
            $this->recordedTranscriptionGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that a transcription was generated matching a given truth test.
     */
    public function assertTranscriptionGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedTranscriptionGenerations))->contains(fn (TranscriptionPrompt $prompt): mixed => $callback($prompt)),
            'An expected transcription generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a transcription was not generated matching a given truth test.
     */
    public function assertTranscriptionNotGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedTranscriptionGenerations))->doesntContain(fn (TranscriptionPrompt $prompt): mixed => $callback($prompt)),
            'An unexpected transcription generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no transcriptions were generated.
     */
    public function assertNoTranscriptionsGenerated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedTranscriptionGenerations,
            'Unexpected transcription generations were recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued transcription generation was recorded matching a given truth test.
     */
    public function assertTranscriptionQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedTranscriptionGenerations))->contains(fn (QueuedTranscriptionPrompt $prompt): mixed => $callback($prompt)),
            'An expected queued transcription generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued transcription generation was not recorded matching a given truth test.
     */
    public function assertTranscriptionNotQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedTranscriptionGenerations))->doesntContain(fn (QueuedTranscriptionPrompt $prompt): mixed => $callback($prompt)),
            'An unexpected queued transcription generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no queued transcription generations were recorded.
     */
    public function assertNoTranscriptionsQueued(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedTranscriptionGenerations,
            'Unexpected queued transcription generations were recorded.'
        );

        return $this;
    }

    /**
     * Determine if transcription generation is faked.
     *
     * @phpstan-assert-if-true FakeTranscriptionGateway $this->fakeTranscriptionGateway()
     */
    public function transcriptionsAreFaked(): bool
    {
        return $this->fakeTranscriptionGateway !== null;
    }

    /**
     * Get the fake transcription gateway.
     */
    public function fakeTranscriptionGateway(): ?FakeTranscriptionGateway
    {
        return $this->fakeTranscriptionGateway;
    }
}
