<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Queue\Console\WorkCommand as BaseWorkCommand;

class WorkCommand extends BaseWorkCommand
{
    /**
     * The console command name.
     */
    protected ?string $signature = 'horizon:work
                            {connection? : The name of the queue connection to work}
                            {--name=default : The name of the worker}
                            {--queue= : The names of the queues to work}
                            {--daemon : Run the worker in daemon mode (Deprecated)}
                            {--once : Only process the next job on the queue}
                            {--concurrency=1 : The number of jobs to process at once}
                            {--stop-when-empty : Stop when the queue is empty}
                            {--delay=0 : The number of seconds to delay failed jobs (Deprecated)}
                            {--backoff=0 : The number of seconds to wait before retrying a job that encountered an uncaught exception}
                            {--max-jobs=0 : The number of jobs to process before stopping}
                            {--max-time=0 : The maximum number of seconds the worker should run}
                            {--force : Force the worker to run even in maintenance mode}
                            {--memory=128 : The memory limit in megabytes}
                            {--sleep=3 : Number of seconds to sleep when no job is available}
                            {--rest=0 : Number of seconds to rest between jobs}
                            {--timeout=60 : The number of seconds a child process can run}
                            {--monitor-interval=1 : The time interval of seconds for monitoring timeout jobs}
                            {--tries=0 : Number of times to attempt a job before logging it failed}
                            {--json : Output the queue worker information as JSON}
                            {--supervisor= : The name of the supervisor the worker belongs to}';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     */
    protected bool $hidden = true;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (config('horizon.fast_termination')) {
            ignore_user_abort(true);
        }

        return parent::handle();
    }
}
