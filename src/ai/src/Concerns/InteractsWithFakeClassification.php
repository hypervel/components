<?php

declare(strict_types=1);

namespace Hypervel\Ai\Concerns;

use Closure;
use Hypervel\Ai\Gateway\FakeClassificationGateway;
use Hypervel\Ai\Prompts\ClassificationPrompt;
use Hypervel\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeClassification
{
    /**
     * The fake classification gateway instance.
     */
    protected ?FakeClassificationGateway $fakeClassificationGateway = null;

    /**
     * All of the recorded classifications.
     */
    protected array $recordedClassifications = [];

    /**
     * Fake classification operations.
     *
     * Tests only. The fake gateway is shared by all requests in the worker.
     */
    public function fakeClassification(Closure|array $responses = []): FakeClassificationGateway
    {
        return $this->fakeClassificationGateway = new FakeClassificationGateway($responses);
    }

    /**
     * Record a classification.
     *
     * Tests only. Recorded prompts remain on the worker-shared manager.
     */
    public function recordClassification(ClassificationPrompt $prompt): self
    {
        $this->recordedClassifications[] = $prompt;

        return $this;
    }

    /**
     * Assert that a classification was performed matching a given truth test.
     */
    public function assertClassified(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedClassifications))->contains(fn (ClassificationPrompt $prompt): mixed => $callback($prompt)),
            'An expected classification was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a classification was not performed matching a given truth test.
     */
    public function assertNotClassified(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedClassifications))->doesntContain(fn (ClassificationPrompt $prompt): mixed => $callback($prompt)),
            'An unexpected classification was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no classifications were performed.
     */
    public function assertNothingClassified(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedClassifications,
            'Unexpected classifications were recorded.'
        );

        return $this;
    }

    /**
     * Determine if classification is faked.
     *
     * @phpstan-assert-if-true FakeClassificationGateway $this->fakeClassificationGateway()
     */
    public function classificationIsFaked(): bool
    {
        return $this->fakeClassificationGateway !== null;
    }

    /**
     * Get the fake classification gateway.
     */
    public function fakeClassificationGateway(): ?FakeClassificationGateway
    {
        return $this->fakeClassificationGateway;
    }
}
