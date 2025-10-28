<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\MasterSupervisorLooped;

class TrimMonitoredJobs
{
    /**
     * The last time the monitored jobs were trimmed.
     */
    public ?CarbonImmutable $lastTrimmed;

    /**
     * How many minutes to wait in between each trim.
     */
    public int $frequency = 1440;

    /**
     * Handle the event.
     */
    public function handle(MasterSupervisorLooped $event): void
    {
        if (! isset($this->lastTrimmed)) {
            $this->frequency = max(1, intdiv(
                config('horizon.trim.monitored', 10080),
                12
            ));

            $this->lastTrimmed = CarbonImmutable::now()->subMinutes($this->frequency + 1);
        }

        if ($this->lastTrimmed->lte(CarbonImmutable::now()->subMinutes($this->frequency))) {
            app(JobRepository::class)->trimMonitoredJobs();

            $this->lastTrimmed = CarbonImmutable::now();
        }
    }
}
