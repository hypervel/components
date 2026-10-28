<?php

declare(strict_types=1);

use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Contracts\Queue\Interruptible;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Capsule\Manager;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobTimedOut;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\Failed\DatabaseUuidFailedJobProvider;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Mockery as m;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Runtime;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/**
 * Exercise the real database job lifecycle without booting an application server.
 */
class TimeoutWorkerJob
{
    /**
     * Block the loop on the first attempt and complete a subsequent delivery.
     */
    public function fire(Job $job, array $data): void
    {
        echo 'attempt:', $job->attempts(), "\n";

        if ($job->attempts() > 1) {
            $job->delete();
            echo "retried\n";

            return;
        }

        if ($data['mode'] === 'suspended') {
            Coroutine::yield();

            throw new RuntimeException('The suspended attempt was unexpectedly resumed.');
        }

        if ($data['mode'] === 'released-timeout') {
            $job->release(30);
            echo "released\n";
            Coroutine::sleep(30);

            return;
        }

        if ($data['mode'] === 'catch-release') {
            try {
                Coroutine::sleep(30);
            } catch (Exception) {
                $job->release(30);
                echo "released\n";
            }

            return;
        }

        if (in_array($data['mode'], ['unwind', 'unwind-stall'], true)) {
            try {
                Coroutine::sleep(30);
            } finally {
                echo "unwind-start\n";
                Coroutine::sleep($data['mode'] === 'unwind-stall' ? 30 : 0.02);
                echo "unwind-finished\n";
            }

            return;
        }

        if (in_array($data['mode'], ['yielding', 'blocked-cleanup'], true)) {
            Coroutine::sleep(30);
        } else {
            while (true);
        }
    }

    /**
     * Require coroutine-aware failure cleanup before recording completion.
     */
    public function failed(array $data, Throwable $exception, string $uuid, Job $job): void
    {
        echo "failed-start\n";
        Coroutine::sleep($data['mode'] === 'yielding' ? 4 : 0.02);
        echo "failed-finished\n";
    }
}

class LateParkTimeoutWorkerJob implements Interruptible, ShouldQueue
{
    use InteractsWithQueue;

    protected ?Channel $gate = null;

    /**
     * Block the loop only after the monitor has declared the timeout.
     */
    public function handle(): void
    {
        echo 'attempt:', $this->job->attempts(), "\n";
        $this->gate = new Channel(1);
        $this->gate->pop(30);

        while (true);
    }

    /**
     * Resume the attempt while its timeout notification is still on the stack.
     */
    public function interrupted(int $signal): void
    {
        $this->gate->push(true);
    }
}

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Coroutine\run(static function () use ($argv): void {
    [$script, $databasePath, $mode] = $argv;
    $container = new Container;
    Container::setInstance($container);
    $container->instance(CallQueuedHandler::class, new CallQueuedHandler(new BusDispatcher($container), $container));
    $database = new Manager($container);
    $database->addConnection(['driver' => 'sqlite', 'database' => $databasePath]);
    $connection = $database->getConnection();
    $connection->statement('CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT, payload TEXT, attempts INTEGER, reserved_at INTEGER, available_at INTEGER, created_at INTEGER)');
    $connection->statement('CREATE TABLE IF NOT EXISTS failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, connection TEXT, queue TEXT, payload TEXT, exception TEXT, failed_at TEXT)');
    $container->make('config')->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'default']);
    $events = new Dispatcher($container);
    $container->instance('events', $events);
    $container->instance(DispatcherContract::class, $events);
    $resolver = $container->make('db.resolver');
    $queue = (new DatabaseQueue($resolver, 'default', 'jobs', retryAfter: 10))
        ->setContainer($container)
        ->setConnectionName('database');
    $failures = new DatabaseUuidFailedJobProvider($resolver, 'default', 'failed_jobs');

    $events->listen(JobFailed::class, static function (JobFailed $event) use ($failures): void {
        $failures->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        echo "failure-recorded\n";
    });
    $events->listen(JobTimedOut::class, static function () use ($mode): void {
        echo "timed-out\n";

        if (in_array($mode, ['reblock', 'blocked-cleanup'], true)) {
            while (true);
        }
    });
    $events->listen(WorkerStopping::class, static function (): void {
        echo "stopping\n";
    });
    Worker::killUsing(static function (): void {
        echo "kill-callback\n";
    });
    Worker::$pausable = false;
    Worker::$restartable = false;

    if ($mode !== 'finish') {
        $queue->pushRaw(json_encode([
            'uuid' => 'timeout-worker-job',
            'job' => $mode === 'late-park' ? CallQueuedHandler::class . '@call' : TimeoutWorkerJob::class . '@fire',
            'data' => $mode === 'late-park'
                ? ['commandName' => LateParkTimeoutWorkerJob::class, 'command' => serialize(new LateParkTimeoutWorkerJob)]
                : ['mode' => $mode],
            'maxTries' => 3,
            'timeout' => 1,
            'failOnTimeout' => $mode !== 'retry',
        ], JSON_THROW_ON_ERROR));
    }

    $manager = m::mock(Factory::class);
    $manager->shouldReceive('connection')->andReturn($queue);
    $exceptions = m::mock(ExceptionHandler::class);
    $exceptions->shouldReceive('report')->andReturnUsing(static function (Throwable $exception): void {
        fwrite(STDERR, (string) $exception);
    });
    $worker = new Worker($manager, $events, $exceptions, static fn (): bool => false);
    $worker->daemon('database', 'default', new WorkerOptions(memory: 0, sleep: 0.01, maxTries: 3, maxJobs: 1));
    echo "finished\n";
});
