<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue\Data;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Support\Facades\DB;

class AfterCommitContractJob implements ShouldQueueAfterCommit
{
    use Dispatchable;
    use Queueable;
}

class BeforeCommitContractJob extends AfterCommitContractJob
{
    public ?bool $afterCommit = false;
}

DB::transaction(static function (): void {
    AfterCommitContractJob::dispatch();
    AfterCommitContractJob::dispatch()->beforeCommit();
    AfterCommitContractJob::dispatch()->beforeCommit()->afterCommit();
    BeforeCommitContractJob::dispatch();
    BeforeCommitContractJob::dispatch()->afterCommit();
});
