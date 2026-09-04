<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;

class QueueProducerStateStore implements NonCopyableContext
{
    private const string CONTEXT_KEY = '__opentelemetry.queue.producers';

    /** @var array<string, non-empty-list<QueueProducerState>|QueueProducerState> */
    protected array $statesByPayload = [];

    /** @var array<string, string> */
    protected array $payloadByUuid = [];

    /**
     * Return producer state for the current coroutine.
     */
    public static function current(): self
    {
        $store = CoroutineContext::get(self::CONTEXT_KEY);

        if ($store instanceof self) {
            return $store;
        }

        $store = new self;
        CoroutineContext::set(self::CONTEXT_KEY, $store);

        return $store;
    }

    /**
     * Retain state by exact payload with an optional framework-UUID fallback.
     */
    public function put(string $payload, QueueProducerState $state): void
    {
        if (! isset($this->statesByPayload[$payload])) {
            $this->statesByPayload[$payload] = $state;
        } elseif ($this->statesByPayload[$payload] instanceof QueueProducerState) {
            $this->statesByPayload[$payload] = [$this->statesByPayload[$payload], $state];
        } else {
            $this->statesByPayload[$payload][] = $state;
        }

        if ($state->uuid !== null) {
            $this->payloadByUuid[$state->uuid] = $payload;
        }
    }

    /**
     * Take state correlated by the exact final encoded payload.
     */
    public function take(string $payload): ?QueueProducerState
    {
        if (! isset($this->statesByPayload[$payload])) {
            return null;
        }

        if ($this->statesByPayload[$payload] instanceof QueueProducerState) {
            $state = $this->statesByPayload[$payload];
        } else {
            // Terminal events cannot distinguish byte-identical payloads, so LIFO discards no
            // correlation fact. Pop the stored list itself: reading it into a local first would
            // separate the array on every pop and make draining a bucket quadratic.
            $state = array_pop($this->statesByPayload[$payload]);

            if ($this->statesByPayload[$payload] !== []) {
                return $state;
            }
        }

        unset($this->statesByPayload[$payload]);

        if ($state->uuid !== null) {
            unset($this->payloadByUuid[$state->uuid]);
        }

        return $state;
    }

    /**
     * Take state through the framework UUID after another finalizer rewrites the payload.
     */
    public function takeUuid(string $uuid): ?QueueProducerState
    {
        $payload = $this->payloadByUuid[$uuid] ?? null;

        return $payload === null ? null : $this->take($payload);
    }
}
