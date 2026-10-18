<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Generator;
use Hypervel\Ai\PendingStep;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<int, StreamEvent>
 */
class StepResult implements IteratorAggregate
{
    /** @var array<int, Closure(StepResponse): void> */
    protected array $callbacks = [];

    protected ?StepResponse $response = null;

    protected bool $resolved = false;

    /** @var StreamEvent[] */
    protected array $buffered = [];

    /**
     * Create a buffered or streamed step result.
     *
     * @param Generator<int, StreamEvent, mixed, null|StepResponse>|StepResponse $source
     * @param null|PendingStep $step the step as sent to the model; null when middleware supplied the response itself
     */
    public function __construct(
        protected Generator|StepResponse $source,
        public readonly ?PendingStep $step = null,
        public readonly ?StepContext $context = null,
        public readonly ?int $startedAt = null,
    ) {
    }

    /**
     * Register a callback to run once the step's response is available.
     *
     * @param Closure(StepResponse): void $callback
     */
    public function then(Closure $callback): static
    {
        if (! $this->resolved) {
            $this->callbacks[] = $callback;
        } elseif ($this->response instanceof StepResponse) {
            $callback($this->response);
        }

        return $this;
    }

    /**
     * Iterate the stream to its end or call response(); a partial iteration cannot be resumed.
     *
     * @return Generator<int, StreamEvent>
     */
    public function getIterator(): Generator
    {
        if ($this->source instanceof StepResponse) {
            $this->resolved || $this->resolve($this->source);

            return;
        }

        yield from $this->buffered;

        if ($this->resolved) {
            $this->buffered = [];

            return;
        }

        while ($this->source->valid()) {
            $this->buffered[] = $event = $this->source->current();

            yield $event;

            $this->source->next();
        }

        $this->resolve($this->source->getReturn());
    }

    /**
     * Determine whether the step response is backed by a stream.
     */
    public function streamed(): bool
    {
        return $this->source instanceof Generator;
    }

    /**
     * Get the step's response, or null when a stream ended without one.
     */
    public function response(): ?StepResponse
    {
        if (! $this->resolved) {
            iterator_to_array($this, false);
        }

        return $this->response;
    }

    /**
     * Resolve the step response and invoke registered callbacks.
     */
    protected function resolve(mixed $response): void
    {
        $this->resolved = true;

        if (! $response instanceof StepResponse) {
            return;
        }

        $this->response = $response;

        foreach ($this->callbacks as $callback) {
            $callback($response);
        }
    }
}
