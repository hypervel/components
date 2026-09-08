<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\Prohibitable;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'queue:clear')]
class ClearCommand extends Command
{
    use ConfirmableTrait;
    use Prohibitable;

    /**
     * The console command name.
     */
    protected ?string $name = 'queue:clear';

    /**
     * The console command description.
     */
    protected string $description = 'Delete all of the jobs from the specified queues';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->isProhibited() || ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $connection = $this->argument('connection');

        if ($connection === null || $connection === '') {
            $connection = $this->hypervel->make('config')->string('queue.default');
        }

        // We need to get the right queue for the connection which is set in the queue
        // configuration file for the application. We will pull it based on the set
        // connection being run for the queue operation currently being executed.
        $queueName = $this->getQueue($connection);

        $queue = $this->hypervel->make('queue')->connection($connection);

        if (! $queue instanceof ClearableQueue) {
            $this->components->error('Clearing queues is not supported on [' . (new ReflectionClass($queue))->getShortName() . ']');

            return self::FAILURE;
        }

        // Queue names such as "0", "01", and "1" are distinct identifiers.
        $queues = (new Stringable($queueName))->explode(',')
            ->map(static fn (string $queue): string => trim($queue))
            ->filter(static fn (string $queue): bool => $queue !== '')
            ->uniqueStrict();

        $count = $queues->reduce(fn (int $carry, string $name): int => $carry + $queue->clear($name), 0);

        $this->components->info(
            sprintf('Cleared %s %s from the [%s] %s', $count, Str::plural('job', $count), $queues->implode(', '), Str::plural('queue', $queues->count()))
        );

        return self::SUCCESS;
    }

    /**
     * Get the queue name to clear.
     */
    protected function getQueue(string $connection): string
    {
        $queue = $this->option('queue');

        return $queue === null || $queue === ''
            ? $this->hypervel->make('config')->string("queue.connections.{$connection}.queue", 'default')
            : $queue;
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
            ['queue', null, InputOption::VALUE_OPTIONAL, 'The names of the queues to clear'],

            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production'],
        ];
    }
}
