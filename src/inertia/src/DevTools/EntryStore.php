<?php

declare(strict_types=1);

namespace Hypervel\Inertia\DevTools;

use Hypervel\Inertia\DevTools\Data\IncomingEntry;
use Hypervel\Support\Facades\Log;
use Throwable;

class EntryStore
{
    use RedactsSensitiveData;

    protected const int SUPPRESS_SECONDS = 30;

    /**
     * Deliberately static: a write failure suppresses recording for a short window. Each
     * long-lived worker keeps its own breaker, and process-per-request setups reset it.
     */
    protected static ?float $suppressedUntil = null;

    protected ?IncomingEntry $pending = null;

    /**
     * Record the entry to persist once the request has been handled.
     */
    public function record(IncomingEntry $entry): void
    {
        $this->pending = $entry;
    }

    /**
     * Get the pending entry.
     */
    public function current(): ?IncomingEntry
    {
        return $this->pending;
    }

    /**
     * Discard the pending entry.
     */
    public function reset(): void
    {
        $this->pending = null;
    }

    /**
     * Persist the pending entry and prune expired entries.
     */
    public function flush(EntriesRepository $repo): void
    {
        $entry = $this->pending;

        if ($entry === null) {
            return;
        }

        $this->pending = null;

        if (static::$suppressedUntil !== null && microtime(true) < static::$suppressedUntil) {
            return;
        }

        try {
            $repo->save($entry->id, $this->redactSensitiveStoragePayload($entry->toArray()));

            $limit = config()->integer('inertia.devtools.storage.limit', 100);

            if ($limit > 0) {
                $repo->enforceTabLimit($entry->tabUuid, $limit);
            }

            // Pruning shares the storage directory, so a storage failure here must trip the
            // same breaker rather than break the response.
            $repo->pruneIfDue();

            static::$suppressedUntil = null;
        } catch (Throwable $e) {
            $firstFailure = static::$suppressedUntil === null;

            static::$suppressedUntil = microtime(true) + self::SUPPRESS_SECONDS;

            if ($firstFailure) {
                try {
                    Log::warning('Inertia DevTools: failed to persist entry: ' . $e->getMessage());
                } catch (Throwable) {
                    // The log often shares the failing storage, such as a full disk, and
                    // recording must still never break the response.
                }
            }
        }
    }

    /**
     * Reset the circuit breaker so recording resumes immediately.
     *
     * Tests only. The breaker is shared by every request in the worker, so a reset during a
     * request resumes writes for all of them while storage may still be failing.
     */
    public static function resetCircuitBreaker(): void
    {
        static::$suppressedUntil = null;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::resetCircuitBreaker();
    }
}
