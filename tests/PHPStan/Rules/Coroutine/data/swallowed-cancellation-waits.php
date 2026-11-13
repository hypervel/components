<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\SwallowedCancellationWaits;

use Hypervel\Contracts\Engine\ChannelInterface;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Database\Connection;
use Hypervel\Support\Facades\DB;
use Throwable;

class WaitingImportJob implements ShouldQueue
{
    private ?Connection $connection = null;

    public function handle(WaitConcurrent $imports, Concurrent $limiter, ChannelInterface $results, ?Connection $connection): void
    {
        try {
            $imports->wait(1.0);
        } catch (Throwable) {
            return;
        }

        try {
            $limiter->create(static function (): void {
            });
        } catch (Throwable) {
            return;
        }

        try {
            $results->pop(1.0);
        } catch (Throwable) {
            return;
        }

        $this->connection = $connection;

        try {
            $this->connection->select('select 1');
        } catch (Throwable) {
            return;
        }

        try {
            $this->connection = DB::connection();
            $this->connection->select('select 1');
        } catch (Throwable) {
            return;
        }
    }
}
