<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;

class QueueProducerStateStore implements NonCopyableContext
{
    private const string CONTEXT_KEY = '__opentelemetry.queue.producers';

    /** @var array<string, QueueProducerState> */
    protected array $states = [];

    /** @var array<string, string> */
    protected array $uuidByPayload = [];

    /** @var array<string, string> */
    protected array $payloadByUuid = [];

    /** @var array<string, QueueProducerState> */
    protected array $timings = [];

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
     * Retain state by framework UUID and the encoded payload emitted by this instrumentation.
     */
    public function put(string $uuid, string $payload, QueueProducerState $state): void
    {
        $this->states[$uuid] = $state;
        $this->uuidByPayload[$payload] = $uuid;
        $this->payloadByUuid[$uuid] = $payload;
    }

    /**
     * Retain metric-only timing state by the final encoded payload.
     */
    public function putTiming(string $payload, QueueProducerState $state): void
    {
        $this->timings[$payload] = $state;
    }

    /**
     * Take state correlated by the exact final encoded payload.
     */
    public function take(string $payload): ?QueueProducerState
    {
        if (isset($this->uuidByPayload[$payload])) {
            return $this->takeUuid($this->uuidByPayload[$payload]);
        }

        if (! isset($this->timings[$payload])) {
            return null;
        }

        $state = $this->timings[$payload];
        unset($this->timings[$payload]);

        return $state;
    }

    /**
     * Take state through the framework UUID after another finalizer rewrites the payload.
     */
    public function takeUuid(string $uuid): ?QueueProducerState
    {
        if (! isset($this->states[$uuid])) {
            return null;
        }

        $state = $this->states[$uuid];
        $payload = $this->payloadByUuid[$uuid];
        unset(
            $this->states[$uuid],
            $this->payloadByUuid[$uuid],
            $this->uuidByPayload[$payload],
        );

        return $state;
    }
}
