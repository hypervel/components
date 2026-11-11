<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue\Data;

use Hypervel\Bus\Batch;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;

Bus::batch([
    new UniqueJobWithUniqueForProperty(),
    new RegularJob(1),
]);

Bus::bulk([
    new UniqueJobWithUniqueForMethod(),
]);

Queue::bulk([
    new UniqueJobWithUniqueForProperty(),
]);

// Chains within a batch are nested arrays and are inspected too.
Bus::batch([
    [
        new RegularJob(1),
        new UniqueJobWithUniqueForProperty(),
    ],
]);

// Not flagged: none of these are ShouldBeUnique.
Bus::batch([
    new RegularJob(1),
    new RegularJob(2),
]);

// Not flagged: dispatching a unique job on its own is the supported path.
UniqueJobWithUniqueForProperty::dispatch();

/** @var list<UniqueJobWithUniqueForProperty> $jobs */
$jobs = [];

Bus::batch($jobs);

// Hypervel: a single job is wrapped into a batch, and adding to a batch bypasses the lock too.
Bus::batch(new UniqueJobWithUniqueForProperty());

function addUniqueJobToBatch(Batch $batch): void
{
    $batch->add([new UniqueJobWithUniqueForMethod()]);
}

// Hypervel: a chain held in a variable is inspected too, and the jobs argument binds by name.
function batchAndBulkUniqueJobs(): void
{
    $chain = [new UniqueJobWithUniqueForProperty()];

    Bus::batch([$chain]);
    Queue::bulk(data: new UniqueJobWithUniqueForProperty(), jobs: [new RegularJob(1)]);
    Queue::bulk(data: '', jobs: [new UniqueJobWithUniqueForProperty()]);
}

// Hypervel: an entry that may be a job or a chain is reported when any job it may hold is unique.
/** @param RegularJob|list<UniqueJobWithUniqueForProperty> $entry */
function batchUniqueJobOrChainEntry(RegularJob|array $entry): void
{
    Bus::batch([$entry]);
}
