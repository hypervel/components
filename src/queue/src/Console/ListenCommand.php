<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hyperf\Command\Command;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Stringable\Str;
use Hypervel\Queue\Listener;
use Hypervel\Queue\ListenerOptions;
use Hypervel\Support\Traits\HasLaravelStyleCommand;

class ListenCommand extends Command
{
    use HasLaravelStyleCommand;

    /**
     * The console command name.
     */
    protected ?string $signature = 'queue:listen
                            {connection? : The name of connection}
                            {--name=default : The name of the worker}
                            {--delay=0 : The number of seconds to delay failed jobs (Deprecated)}
                            {--backoff=0 : The number of seconds to wait before retrying a job that encountered an uncaught exception}
                            {--force : Force the worker to run even in maintenance mode}
                            {--memory=128 : The memory limit in megabytes}
                            {--queue= : The queue to listen on}
                            {--sleep=3 : Number of seconds to sleep when no job is available}
                            {--rest=0 : Number of seconds to rest between jobs}
                            {--timeout=60 : The number of seconds a child process can run}
                            {--tries=1 : Number of times to attempt a job before logging it failed}';

    /**
     * The console command description.
     */
    protected string $description = 'Listen to a given queue';

    /**
     * The queue listener instance.
     */
    protected Listener $listener;

    /**
     * Create a new queue listen command.
     */
    public function __construct(Listener $listener)
    {
        parent::__construct();

        $this->setOutputHandler($this->listener = $listener);
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // We need to get the right queue for the connection which is set in the queue
        // configuration file for the application. We will pull it based on the set
        // connection being run for the queue operation currently being executed.
        $queue = $this->getQueue(
            $connection = $this->input->getArgument('connection')
        );

        $this->info(sprintf('Processing jobs from the [%s] %s.', $queue, Str::of('queue')->plural(explode(',', $queue))));

        $this->listener->listen(
            $connection,
            $queue,
            $this->gatherOptions()
        );
    }

    /**
     * Get the name of the queue connection to listen on.
     */
    protected function getQueue(?string $connection): string
    {
        $connection = $connection ?: $this->app->get(ConfigInterface::class)->get('queue.default');

        return $this->input->getOption('queue') ?: $this->app->get(ConfigInterface::class)->get(
            "queue.connections.{$connection}.queue",
            'default'
        );
    }

    /**
     * Get the listener options for the command.
     */
    protected function gatherOptions(): ListenerOptions
    {
        $backoff = $this->hasOption('backoff')
            ? $this->option('backoff')
            : $this->option('delay');

        return new ListenerOptions(
            name: $this->option('name'),
            environment: $this->option('env'),
            backoff: (int) $backoff,
            memory: (int) $this->option('memory'),
            timeout: (int) $this->option('timeout'),
            sleep: (int) $this->option('sleep'),
            rest: (int) $this->option('rest'),
            maxTries: (int) $this->option('tries'),
            force: (bool) $this->option('force')
        );
    }

    /**
     * Set the options on the queue listener.
     */
    protected function setOutputHandler(Listener $listener): void
    {
        $listener->setOutputHandler(function ($type, $line) {
            $this->output->write($line);
        });
    }
}
