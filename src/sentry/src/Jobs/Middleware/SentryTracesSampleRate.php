<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Jobs\Middleware;

use Closure;
use InvalidArgumentException;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;

/**
 * Job middleware that overrides the Sentry trace sampling decision for the job's transaction.
 *
 * When applied to a job, this middleware re-samples the transaction at the given rate, allowing
 * you to control trace volume for specific jobs independently of the global sample rate.
 *
 * If the transaction was not sampled by the global `traces_sample_rate` or `traces_sampler`,
 * this middleware will not force-enable it — it can only downsample, not upsample.
 *
 * Usage:
 *
 *     public function middleware(): array
 *     {
 *         return [
 *             new SentryTracesSampleRate(0.1), // Sample 10% of this job's traces
 *         ];
 *     }
 */
class SentryTracesSampleRate
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        private readonly float $sampleRate,
    ) {
        if ($sampleRate < 0.0 || $sampleRate > 1.0) {
            throw new InvalidArgumentException('Sample rate must be between 0.0 and 1.0.');
        }
    }

    /**
     * Handle the queued job.
     */
    public function handle(object $job, Closure $next): void
    {
        if (app()->bound(HubInterface::class)) {
            $transaction = SentrySdk::getCurrentHub()->getTransaction();

            if ($transaction !== null && $transaction->getSampled()) {
                $transaction->setSampled($this->shouldSample());
            }
        }

        $next($job);
    }

    /**
     * Determine whether the transaction should be sampled.
     */
    private function shouldSample(): bool
    {
        if ($this->sampleRate <= 0.0) {
            return false;
        }

        if ($this->sampleRate >= 1.0) {
            return true;
        }

        /* @noinspection RandomApiMigrationInspection */
        return mt_rand(0, mt_getrandmax() - 1) / mt_getrandmax() < $this->sampleRate;
    }
}
