<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Fixtures;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;

class EloquentTransactionWithAfterCommitTestsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $email)
    {
        // ...
    }

    /**
     * Insert the password reset token in a transaction.
     */
    public function handle(): void
    {
        DB::transaction(function (): void {
            DB::table('password_reset_tokens')->insert([
                ['email' => $this->email, 'token' => sha1($this->email), 'created_at' => CarbonImmutable::now()],
            ]);
        });
    }
}
