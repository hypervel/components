<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue\Data;

use Hypervel\Bus\Batch;
use Hypervel\Bus\PendingBatch;
use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Support\Facades\Bus;

Bus::batch([
    new BatchableJobWithCancellationCheck(),
    new RegularJob(1),
]);

// Chains within a batch are nested arrays and need the trait too.
Bus::batch([
    [
        new BatchableJobWithCancellationCheck(),
        new RegularJob(2),
    ],
]);

// Not flagged: every job uses Batchable.
Bus::batch([
    new BatchableJobWithCancellationCheck(),
    new BatchableJobWithoutCancellationCheck(),
]);

// Not flagged: uses Batchable, though it is not a queued job.
Bus::batch([
    new BatchableNonQueuedClass(),
]);

// Hypervel: unlike upstream, a variable's job type is checked too.
/** @var list<RegularJob> $jobs */
$jobs = [];

Bus::batch($jobs);

// Hypervel: PendingBatch requires the trait on every job except a closure or a nested batch, while
// a job typed by an interface may be one that uses it.
class SyncReport
{
    public function handle(): void
    {
    }
}

Bus::batch([
    new SyncReport(),
    static function (): void {
    },
]);

function batchQueuedJob(ShouldQueue $job): void
{
    Bus::batch([$job]);
}

// Hypervel: a single job, spread jobs, a dispatcher instance and adding to a pending or running batch
// need the trait too. Bulk dispatch is not a batch.
/** @param list<RegularJob> $regularJobs */
function batchJobs(QueueingDispatcher $dispatcher, PendingBatch $pendingBatch, ?Batch $batch, array $regularJobs): void
{
    Bus::batch(new RegularJob(3));
    $dispatcher->batch([new RegularJob(4)]);
    $pendingBatch->add(new RegularJob(5));
    $batch?->add([
        new RegularJob(6),
        static function (): void {
        },
    ]);
    Bus::bulk([new RegularJob(7)]);
    Bus::batch([...$regularJobs]);
}

// Hypervel: a chain held in a variable needs the trait too, and each job of a list held in a
// variable is checked on its own.
function batchVariables(): void
{
    $chain = [new RegularJob(8)];
    $jobs = [new BatchableJobWithCancellationCheck(), new RegularJob(9), new RegularJob(10)];

    Bus::batch([$chain]);
    Bus::batch($jobs);
}

// Hypervel: an entry that may be a job or a chain is reported when every job it may hold lacks the trait.
/**
 * @param RegularJob|list<RegularJob> $regularEntry
 * @param BatchableJobWithCancellationCheck|list<RegularJob> $mixedEntry
 */
function batchJobOrChainEntries(RegularJob|array $regularEntry, BatchableJobWithCancellationCheck|array $mixedEntry): void
{
    Bus::batch([$regularEntry]);
    Bus::batch([$mixedEntry]);
}
