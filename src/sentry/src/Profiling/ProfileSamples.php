<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Profiling;

use ExcimerLog;

/**
 * @internal
 */
class ProfileSamples
{
    // Sentry's Profile discards a recording whose last converted sample falls after 30 seconds.
    private const float MAX_DURATION = 30.0;

    private array $samples = [];

    private array $frames = [];

    private ?float $idleSince = null;

    private bool $tooLong = false;

    /**
     * Record the running stack or the beginning of a gap in this transaction's work.
     */
    public function record(ExcimerLog $log, bool $belongsToTransaction): void
    {
        if ($this->tooLong) {
            return;
        }

        if (! $belongsToTransaction) {
            if ($this->samples !== []) {
                $this->idleSince ??= $log[0]->getTimestamp();
            }

            return;
        }

        $entry = $log[0];
        if ($this->idleSince !== null) {
            $this->samples[] = ['trace' => [], 'timestamp' => $this->idleSince];
            $this->idleSince = null;
        }

        $trace = $entry->getTrace();
        // Flushing one native sample at a time loses Excimer's frame sharing across samples.
        foreach ($trace as $index => $frame) {
            $trace[$index] = $this->frames[serialize($frame)] ??= $frame;
        }

        $previous = array_key_last($this->samples);
        $this->samples[] = [
            'trace' => $trace,
            'timestamp' => $entry->getTimestamp(),
            'event_count' => $entry->getEventCount(),
        ];

        // The SDK can backdate a coalesced sample, but no earlier than its predecessor.
        // Keep both so the SDK still records the original reason for dropping the profile.
        if ($previous !== null && $this->samples[$previous]['timestamp'] > self::MAX_DURATION) {
            $this->samples = array_slice($this->samples, -2);
            $this->frames = [];
            $this->tooLong = true;
        }
    }

    /**
     * Return the samples for the SDK and release the frame lookup.
     */
    public function finish(): array
    {
        $this->frames = [];

        return $this->samples;
    }
}
