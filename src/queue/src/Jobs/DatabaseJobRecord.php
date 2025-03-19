<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Hyperf\Support\Traits\InteractsWithTime;
use stdClass;

class DatabaseJobRecord
{
    use InteractsWithTime;

    /**
     * Create a new job record instance.
     */
    public function __construct(
        protected stdClass $record
    ) {
    }

    /**
     * Increment the number of times the job has been attempted.
     */
    public function increment(): int
    {
        ++$this->record->attempts;

        return $this->record->attempts;
    }

    /**
     * Update the "reserved at" timestamp of the job.
     */
    public function touch(): int
    {
        $this->record->reserved_at = $this->currentTime();

        return $this->record->reserved_at;
    }

    /**
     * Dynamically access the underlying job information.
     */
    public function __get(string $key)
    {
        return $this->record->{$key};
    }
}
