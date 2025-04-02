<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hyperf\Command\Command;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Stringable\Str;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Queue\Contracts\ClearableQueue;
use Hypervel\Queue\Contracts\Factory as FactoryContract;
use Hypervel\Support\Traits\HasLaravelStyleCommand;
use ReflectionClass;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ClearCommand extends Command
{
    use ConfirmableTrait;
    use HasLaravelStyleCommand;

    /**
     * The console command name.
     */
    protected ?string $name = 'queue:clear';

    /**
     * The console command description.
     */
    protected string $description = 'Delete all of the jobs from the specified queue';

    /**
     * Execute the console command.
     */
    public function handle(): ?int
    {
        if (! $this->confirmToProceed()) {
            return 1;
        }

        $connection = $this->argument('connection')
            ?: $this->app->get(ConfigInterface::class)->get('queue.default');

        // We need to get the right queue for the connection which is set in the queue
        // configuration file for the application. We will pull it based on the set
        // connection being run for the queue operation currently being executed.
        $queueName = $this->getQueue($connection);

        $queue = $this->app->get(FactoryContract::class)->connection($connection);

        if ($queue instanceof ClearableQueue) {
            $count = $queue->clear($queueName);

            $this->info('Cleared ' . $count . ' ' . Str::plural('job', $count) . ' from the [' . $queueName . '] queue');
        } else {
            $this->error('Clearing queues is not supported on [' . (new ReflectionClass($queue))->getShortName() . ']');

            return 1;
        }

        return 0;
    }

    /**
     * Get the queue name to clear.
     */
    protected function getQueue(string $connection): string
    {
        return $this->option('queue') ?: $this->app->get(ConfigInterface::class)->get(
            "queue.connections.{$connection}.queue",
            'default'
        );
    }

    /**
     *  Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['connection', InputArgument::OPTIONAL, 'The name of the queue connection to clear'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['queue', null, InputOption::VALUE_OPTIONAL, 'The name of the queue to clear'],

            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production'],
        ];
    }
}
