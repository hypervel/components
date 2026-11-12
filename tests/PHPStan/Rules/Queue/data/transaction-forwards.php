<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue\Data;

use Hypervel\Support\Facades\DB;

// A booted forward may move a job without its own connection, so only the explicit connection is flagged.
DB::transaction(static function (): void {
    QueueableNotifyOwner::dispatch();
    QueueableNotifyOwner::dispatch()->onConnection('transaction-redis');
});
