<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;
use Hypervel\Contracts\Bus\BatchRepository;
use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Support\Collection;
use Hypervel\Support\Pipeline;
use RuntimeException;

class Dispatcher implements QueueingDispatcher
{
    /**
     * The pipeline instance for the bus.
     */
    protected Pipeline $pipeline;

    /**
     * The pipes to send commands through before dispatching.
     */
    protected array $pipes = [];

    /**
     * The command to handler mapping for non-self-handling events.
     */
    protected array $handlers = [];

    /**
     * Create a new command dispatcher instance.
     *
     * @param Container $container the container implementation
     * @param null|Closure $queueResolver the queue resolver callback
     */
    public function __construct(
        protected Container $container,
        protected ?Closure $queueResolver = null
    ) {
        $this->pipeline = new Pipeline($container);
    }

    /**
     * Dispatch a command to its appropriate handler.
     */
    public function dispatch(mixed $command): mixed
    {
        return $this->queueResolver && $this->commandShouldBeQueued($command)
            ? $this->dispatchToQueue($command)
            : $this->dispatchNow($command);
    }

    /**
     * Dispatch a command to its appropriate handler in the current process.
     *
     * Queueable jobs will be dispatched to the "sync" queue.
     */
    public function dispatchSync(mixed $command, mixed $handler = null): mixed
    {
        if ($this->queueResolver
            && $this->commandShouldBeQueued($command)
            && method_exists($command, 'onConnection')
        ) {
            return $this->dispatchToQueue($command->onConnection('sync'));
        }

        return $this->dispatchNow($command, $handler);
    }

    /**
     * Dispatch a command to its appropriate handler in the current process without using the synchronous queue.
     */
    public function dispatchNow(mixed $command, mixed $handler = null): mixed
    {
        $uses = class_uses_recursive($command);

        if (in_array(InteractsWithQueue::class, $uses)
            && in_array(Queueable::class, $uses)
            && ! $command->job
        ) {
            $command->setJob(new SyncJob($this->container, json_encode([]), 'sync', 'sync'));
        }

        if ($handler || $handler = $this->getCommandHandler($command)) {
            $callback = function ($command) use ($handler) {
                $method = method_exists($handler, 'handle') ? 'handle' : '__invoke';

                return $handler->{$method}($command);
            };
        } elseif (! method_exists($this->container, 'call')) {
            throw new RuntimeException('The container must implement the `call` method.');
        } else {
            $callback = function ($command) {
                $method = method_exists($command, 'handle') ? 'handle' : '__invoke';

                /* @phpstan-ignore-next-line */
                return $this->container->call([$command, $method]);
            };
        }

        return $this->pipeline
            ->send($command)
            ->through($this->pipes)
            ->then($callback);
    }

    /**
     * Attempt to find the batch with the given ID.
     */
    public function findBatch(string $batchId): ?Batch
    {
        return $this->container
            ->get(BatchRepository::class)
            ->find($batchId);
    }

    /**
     * Create a new batch of queueable jobs.
     *
     * @param array|Collection|mixed $jobs
     */
    public function batch(mixed $jobs): PendingBatch
    {
        return new PendingBatch($this->container, Collection::wrap($jobs));
    }

    /**
     * Create a new chain of queueable jobs.
     */
    public function chain(array|Collection $jobs): PendingChain
    {
        $jobs = Collection::wrap($jobs);
        $jobs = ChainedBatch::prepareNestedBatches($jobs);

        return new PendingChain($jobs->shift(), $jobs->toArray());
    }

    /**
     * Determine if the given command has a handler.
     */
    public function hasCommandHandler(mixed $command): bool
    {
        return array_key_exists(get_class($command), $this->handlers);
    }

    /**
     * Retrieve the handler for a command.
     *
     * @return bool|mixed
     */
    public function getCommandHandler(mixed $command): mixed
    {
        if ($this->hasCommandHandler($command)) {
            return $this->container->get($this->handlers[get_class($command)]);
        }

        return false;
    }

    /**
     * Determine if the given command should be queued.
     */
    protected function commandShouldBeQueued(mixed $command): bool
    {
        return $command instanceof ShouldQueue;
    }

    /**
     * Dispatch a command to its appropriate handler behind a queue.
     *
     * @throws RuntimeException
     */
    public function dispatchToQueue(mixed $command): mixed
    {
        $connection = $command->connection ?? null;

        $queue = call_user_func($this->queueResolver, $connection);

        if (! $queue instanceof Queue) {
            throw new RuntimeException('Queue resolver did not return a Queue implementation.');
        }

        if (method_exists($command, 'queue')) {
            return $command->queue($queue, $command);
        }

        return $this->pushCommandToQueue($queue, $command);
    }

    /**
     * Push the command onto the given queue instance.
     */
    protected function pushCommandToQueue(Queue $queue, mixed $command): mixed
    {
        if (isset($command->queue, $command->delay)) {
            return $queue->laterOn($command->queue, $command->delay, $command);
        }

        if (isset($command->queue)) {
            return $queue->pushOn($command->queue, $command);
        }

        if (isset($command->delay)) {
            return $queue->later($command->delay, $command);
        }

        return $queue->push($command);
    }

    /**
     * Dispatch a command to its appropriate handler after the current process.
     */
    public function dispatchAfterResponse(mixed $command, mixed $handler = null): void
    {
        Coroutine::defer(fn () => $this->dispatchSync($command, $handler));
    }

    /**
     * Set the pipes through which commands should be piped before dispatching.
     */
    public function pipeThrough(array $pipes): static
    {
        $this->pipes = $pipes;

        return $this;
    }

    /**
     * Map a command to a handler.
     */
    public function map(array $map): static
    {
        $this->handlers = array_merge($this->handlers, $map);

        return $this;
    }
}
