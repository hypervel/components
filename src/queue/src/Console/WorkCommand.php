<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hyperf\Command\Command;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Stringable\Str;
use Hypervel\Cache\Contracts\Factory as CacheFactory;
use Hypervel\Queue\Contracts\Job;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobReleasedAfterException;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Support\Carbon;
use Hypervel\Support\Traits\HasLaravelStyleCommand;
use Hypervel\Support\Traits\InteractsWithTime;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

class WorkCommand extends Command
{
    use HasLaravelStyleCommand;
    use InteractsWithTime;

    /**
     * The console command name.
     */
    protected ?string $signature = 'queue:work
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
                            {--tries=1 : Number of times to attempt a job before logging it failed}
                            {--json : Output the queue worker information as JSON}';

    /**
     * The console command description.
     */
    protected string $description = 'Start processing jobs on the queue as a daemon';

    /**
     * Holds the start time of the last processed job, if any.
     */
    protected ?float $latestStartedAt = null;

    /**
     * Indicates if the worker's event listeners have been registered.
     */
    protected static bool $hasRegisteredListeners = false;

    /**
     * Create a new queue work command.
     */
    public function __construct(
        protected ContainerInterface $container,
        protected ConfigInterface $config,
        protected Worker $worker,
        protected CacheFactory $cache
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): ?int
    {
        if ($this->option('once')) {
            return $this->worker->sleep((float) $this->option('sleep'));
        }

        // We'll listen to the processed and failed events so we can write information
        // to the console as jobs are processed, which will let the developer watch
        // which jobs are coming through a queue and be informed on its progress.
        $this->listenForEvents();

        $connection = $this->argument('connection')
            ?: $this->config->get('queue.default');

        // We need to get the right queue for the connection which is set in the queue
        // configuration file for the application. We will pull it based on the set
        // connection being run for the queue operation currently being executed.
        $queue = $this->getQueue($connection);

        if (! $this->outputUsingJson()) {
            $this->info(
                sprintf('Processing jobs from the [%s] %s.', $queue, Str::of('queue')->plural(explode(',', $queue)))
            );
        }

        return $this->runWorker(
            $connection,
            $queue
        );
    }

    /**
     * Run the worker instance.
     */
    protected function runWorker(string $connection, string $queue): ?int
    {
        return $this->worker
            ->setName($this->option('name'))
            ->setCache($this->cache)
            ->{$this->option('once') ? 'runNextJob' : 'daemon'}(
                $connection,
                $queue,
                $this->gatherWorkerOptions()
            );
    }

    /**
     * Gather all of the queue worker options as a single object.
     */
    protected function gatherWorkerOptions(): WorkerOptions
    {
        $concurrencyConfig = (int) $this->config->get('queue.concurrency_number', 1);
        $concurrencyOption = (int) $this->option('concurrency');
        $concurrency = $concurrencyOption > 1
            ? $concurrencyOption
            : max(1, $concurrencyConfig);

        return new WorkerOptions(
            $this->option('name'),
            (int) max($this->option('backoff'), $this->option('delay')),
            (int) $this->option('memory'),
            (int) $this->option('timeout'),
            (int) $this->option('sleep'),
            (int) $this->option('tries'),
            (bool) $this->option('force'),
            (bool) $this->option('stop-when-empty'),
            (int) $this->option('max-jobs'),
            (int) $this->option('max-time'),
            (int) $this->option('rest'),
            $concurrency,
            (int) $this->option('monitor-interval'),
        );
    }

    /**
     * Listen for the queue events in order to update the console output.
     */
    protected function listenForEvents(): void
    {
        if (static::$hasRegisteredListeners) {
            return;
        }

        $event = $this->container->get(EventDispatcherInterface::class);
        $event->listen(JobProcessing::class, function ($event) {
            $this->writeOutput($event->job, 'starting');
        });

        $event->listen(JobProcessed::class, function ($event) {
            $this->writeOutput($event->job, 'success');
        });

        $event->listen(JobReleasedAfterException::class, function ($event) {
            $this->writeOutput($event->job, 'released_after_exception');
        });

        $event->listen(JobFailed::class, function ($event) {
            $this->writeOutput($event->job, 'failed', $event->exception);

            $this->logFailedJob($event);
        });

        static::$hasRegisteredListeners = true;
    }

    /**
     * Write the status output for the queue worker for JSON or TTY.
     * @param mixed $status
     */
    protected function writeOutput(Job $job, $status, ?Throwable $exception = null): void
    {
        $this->outputUsingJson()
            ? $this->writeOutputAsJson($job, $status, $exception)
            : $this->writeOutputForCli($job, $status);
    }

    /**
     * Format the status output for the queue worker.
     */
    protected function writeOutputForCli(Job $job, string $status): void
    {
        $type = match ($status) {
            'Processing' => 'comment',
            'Processed' => 'info',
            'Failed' => 'error',
            default => 'comment',
        };

        $this->output->writeln(sprintf(
            "<{$type}>[%s][%s] %s</{$type}> %s",
            Carbon::now()->format('Y-m-d H:i:s'),
            $job->getJobId(),
            str_pad("{$status}:", 11),
            $job->resolveName()
        ));
    }

    /**
     * Write the status output for the queue worker in JSON format.
     * @param mixed $status
     */
    protected function writeOutputAsJson(Job $job, $status, ?Throwable $exception = null): void
    {
        $log = array_filter([
            'level' => $status === 'starting' || $status === 'success' ? 'info' : 'warning',
            'id' => $job->getJobId(),
            'uuid' => $job->uuid(),
            'connection' => $job->getConnectionName(),
            'queue' => $job->getQueue(),
            'job' => $job->resolveName(),
            'status' => $status,
            'result' => match (true) {
                $job->isDeleted() => 'deleted',
                $job->isReleased() => 'released',
                $job->hasFailed() => 'failed',
                default => '',
            },
            'attempts' => $job->attempts(),
            'exception' => $exception ? $exception::class : '',
            'message' => $exception?->getMessage(),
            'timestamp' => $this->now()->format('Y-m-d\TH:i:s.uP'),
        ]);

        if ($status === 'starting') {
            $this->latestStartedAt = microtime(true);
        } else {
            $log['duration'] = round(microtime(true) - $this->latestStartedAt, 6);
        }

        $this->output->writeln(json_encode($log));
    }

    /**
     * Get the current date / time.
     */
    protected function now(): Carbon
    {
        $queueTimezone = $this->config->get('queue.output_timezone');

        if ($queueTimezone
            && $queueTimezone !== $this->config->get('app.timezone')
        ) {
            return Carbon::now()->setTimezone($queueTimezone);
        }

        return Carbon::now();
    }

    /**
     * Store a failed job event.
     */
    protected function logFailedJob(JobFailed $event): void
    {
        $this->container->get(FailedJobProviderInterface::class)
            ->log(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getRawBody(),
                $event->exception
            );
    }

    /**
     * Get the queue name for the worker.
     */
    protected function getQueue(?string $connection): string
    {
        return $this->option('queue') ?: $this->config->get(
            "queue.connections.{$connection}.queue",
            'default'
        );
    }

    /**
     * Determine if the worker should output using JSON.
     */
    protected function outputUsingJson(): bool
    {
        if (! $this->hasOption('json')) {
            return false;
        }

        return $this->option('json');
    }

    /**
     * Reset static variables.
     */
    public static function flushState(): void
    {
        static::$hasRegisteredListeners = false;
    }
}
