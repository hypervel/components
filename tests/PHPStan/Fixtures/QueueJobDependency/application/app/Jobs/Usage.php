<?php

declare(strict_types=1);

namespace QueueJobDependency\Jobs;

use Hypervel\Support\Facades\DB;

class Usage
{
    public function store(): void
    {
        DB::transaction(static function (): void {
            AttributeJob::dispatch();
            ConstructedJob::dispatch();
            ReportJob::dispatch();
        });
    }

    public function storeOnDefaultConnection(): void
    {
        // The default database connection is pgsql, while DatabaseJob's queue stores jobs on mysql.
        DB::transaction(static function (): void {
            DatabaseJob::dispatch();
        });

        // usingConnection() replaces the default, so the transaction may run on the queue's connection.
        DB::usingConnection('mysql', static function (): void {
            DB::transaction(static function (): void {
                DatabaseJob::dispatch();
            });
        });
    }
}
