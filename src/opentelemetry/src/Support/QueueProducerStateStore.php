<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;

class QueueProducerStateStore implements NonCopyableContext
{
    private const string CONTEXT_KEY = '__opentelemetry.queue.producers';

    /** @var array<string, QueueProducerState> */
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
        $this->statesByPayload[$payload] = $state;

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

        $state = $this->statesByPayload[$payload];
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
