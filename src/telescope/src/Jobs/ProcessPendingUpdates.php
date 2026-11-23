<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Jobs;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Collection;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\EntryUpdate;

class ProcessPendingUpdates implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param Collection<int, EntryUpdate> $pendingUpdates the pending entry updates
     * @param int $attempt the number of times the job has been attempted
     */
    public function __construct(
        public Collection $pendingUpdates,
        public int $attempt = 0
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(EntriesRepository $repository): void
    {
        ++$this->attempt;

        $delay = config()->nullableInteger('telescope.queue.delay', null);

        $repository->update($this->pendingUpdates)?->whenNotEmpty(
            fn ($pendingUpdates) => static::dispatchIf(
                $this->attempt < 3,
                $pendingUpdates,
                $this->attempt
            )->onConnection(
                config()->nullableString('telescope.queue.connection', null)
            )->onQueue(
                config()->nullableString('telescope.queue.queue', null)
            )->delay($delay !== null && $delay > 0 ? now()->addSeconds($delay) : null),
        );
    }
}
