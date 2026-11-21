<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Bus\Fixtures;

use Hypervel\Bus\BatchRepository;
use Hypervel\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

trait TestsBatchReopening
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testRejectedReopensRestoreTheFinishAndOnlyReportNewCompletion(bool $recoverBeforeAdding): void
    {
        $repository = $this->app->make(BatchRepository::class);
        $batch = Bus::batch([])->allowFailures()->dispatch();
        $repository->incrementTotalJobs($batch->id, 1);
        $batch->recordFailedJob('first', new RuntimeException);
        $finishedAt = $batch->fresh()->finishedAt;
        if ($recoverBeforeAdding) {
            $batch->recordSuccessfulJob('first');
        }
        $repository->incrementTotalJobs($batch->id, 1);
        $repository->incrementTotalJobs($batch->id, 1);
        if (! $recoverBeforeAdding) {
            $batch->recordSuccessfulJob('first');
        }

        $this->assertNull($repository->releaseJobs($batch->id, 1));
        $counts = $repository->releaseJobs($batch->id, 1);
        if ($recoverBeforeAdding) {
            $this->assertNull($counts);
        } else {
            $this->assertSame(0, $counts->pendingJobs);
            $this->assertFalse($counts->completedFirstPass);
        }
        $batch = $batch->fresh();
        $this->assertEquals($finishedAt, $batch->finishedAt);
        $this->assertSame(1, $batch->totalJobs);
        $this->assertSame(0, $batch->pendingJobs);
    }
}
