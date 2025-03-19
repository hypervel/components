<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Closure;
use Hyperf\Collection\Arr;
use Hyperf\Collection\Collection;
use Hypervel\Bus\Batch;
use Hypervel\Bus\ChainedBatch;
use Hypervel\Bus\Contracts\BatchRepository;
use Hypervel\Bus\Contracts\QueueingDispatcher;
use Hypervel\Bus\PendingBatch;
use Hypervel\Bus\PendingChain;
use Hypervel\Support\Traits\ReflectsClosures;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

class BusFake implements Fake, QueueingDispatcher
{
    use ReflectsClosures;

    /**
     * The job types that should be intercepted instead of dispatched.
     */
    protected array $jobsToFake = [];

    /**
     * The job types that should be dispatched instead of faked.
     */
    protected array $jobsToDispatch = [];

    /**
     * The fake repository to track batched jobs.
     */
    protected BatchRepository $batchRepository;

    /**
     * The commands that have been dispatched.
     */
    protected array $commands = [];

    /**
     * The commands that have been dispatched synchronously.
     */
    protected array $commandsSync = [];

    /**
     * The commands that have been dispatched after the response has been sent.
     */
    protected array $commandsAfterResponse = [];

    /**
     * The batches that have been dispatched.
     */
    protected array $batches = [];

    /**
     * Indicates if commands should be serialized and restored when pushed to the Bus.
     */
    protected bool $serializeAndRestore = false;

    /**
     * Create a new bus fake instance.
     *
     * @param QueueingDispatcher $dispatcher the original Bus dispatcher implementation
     */
    public function __construct(
        public QueueingDispatcher $dispatcher,
        array|string $jobsToFake = [],
        ?BatchRepository $batchRepository = null
    ) {
        $this->jobsToFake = Arr::wrap($jobsToFake);
        $this->batchRepository = $batchRepository ?: new BatchRepositoryFake();
    }

    /**
     * Specify the jobs that should be dispatched instead of faked.
     */
    public function except(array|string $jobsToDispatch): static
    {
        $this->jobsToDispatch = array_merge($this->jobsToDispatch, Arr::wrap($jobsToDispatch));

        return $this;
    }

    /**
     * Assert if a job was dispatched based on a truth-test callback.
     */
    public function assertDispatched(Closure|string $command, null|callable|int $callback = null): void
    {
        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        }

        if (is_numeric($callback)) {
            $this->assertDispatchedTimes($command, $callback);
            return;
        }

        PHPUnit::assertTrue(
            $this->dispatched($command, $callback)->count() > 0
                || $this->dispatchedAfterResponse($command, $callback)->count() > 0
                || $this->dispatchedSync($command, $callback)->count() > 0,
            "The expected [{$command}] job was not dispatched."
        );
    }

    /**
     * Assert if a job was pushed a number of times.
     */
    public function assertDispatchedTimes(Closure|string $command, int $times = 1): void
    {
        $callback = null;

        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        }

        $count = $this->dispatched($command, $callback)->count()
            + $this->dispatchedAfterResponse($command, $callback)->count()
            + $this->dispatchedSync($command, $callback)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected [{$command}] job was pushed {$count} times instead of {$times} times."
        );
    }

    /**
     * Determine if a job was dispatched based on a truth-test callback.
     */
    public function assertNotDispatched(Closure|string $command, ?callable $callback = null): void
    {
        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        }

        PHPUnit::assertTrue(
            $this->dispatched($command, $callback)->count() === 0
                && $this->dispatchedAfterResponse($command, $callback)->count() === 0
                && $this->dispatchedSync($command, $callback)->count() === 0,
            "The unexpected [{$command}] job was dispatched."
        );
    }

    /**
     * Assert that no jobs were dispatched.
     */
    public function assertNothingDispatched(): void
    {
        $commandNames = implode("\n- ", array_keys($this->commands));

        PHPUnit::assertEmpty($this->commands, "The following jobs were dispatched unexpectedly:\n\n- {$commandNames}\n");
    }

    /**
     * Assert if a job was explicitly dispatched synchronously based on a truth-test callback.
     */
    public function assertDispatchedSync(Closure|string $command, null|callable|int $callback = null): void
    {
        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        }

        if (is_numeric($callback)) {
            $this->assertDispatchedSyncTimes($command, $callback);
            return;
        }

        PHPUnit::assertTrue(
            $this->dispatchedSync($command, $callback)->count() > 0,
            "The expected [{$command}] job was not dispatched synchronously."
        );
    }

    /**
     * Assert if a job was pushed synchronously a number of times.
     */
    public function assertDispatchedSyncTimes(Closure|string $command, int $times = 1): void
    {
        $callback = null;

        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        }

        $count = $this->dispatchedSync($command, $callback)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected [{$command}] job was synchronously pushed {$count} times instead of {$times} times."
        );
    }

    /**
     * Determine if a job was dispatched based on a truth-test callback.
     */
    public function assertNotDispatchedSync(Closure|string $command, ?callable $callback = null): void
    {
        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        }

        PHPUnit::assertCount(
            0,
            $this->dispatchedSync($command, $callback),
            "The unexpected [{$command}] job was dispatched synchronously."
        );
    }

    /**
     * Assert if a job was dispatched after the response was sent based on a truth-test callback.
     */
    public function assertDispatchedAfterResponse(Closure|string $command, null|callable|int $callback = null): void
    {
        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        }

        if (is_numeric($callback)) {
            $this->assertDispatchedAfterResponseTimes($command, $callback);
            return;
        }

        PHPUnit::assertTrue(
            $this->dispatchedAfterResponse($command, $callback)->count() > 0,
            "The expected [{$command}] job was not dispatched after sending the response."
        );
    }

    /**
     * Assert if a job was pushed after the response was sent a number of times.
     */
    public function assertDispatchedAfterResponseTimes(Closure|string $command, int $times = 1): void
    {
        $callback = null;

        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        }

        $count = $this->dispatchedAfterResponse($command, $callback)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected [{$command}] job was pushed {$count} times instead of {$times} times."
        );
    }

    /**
     * Determine if a job was dispatched based on a truth-test callback.
     */
    public function assertNotDispatchedAfterResponse(Closure|string $command, ?callable $callback = null): void
    {
        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        }

        PHPUnit::assertCount(
            0,
            $this->dispatchedAfterResponse($command, $callback),
            "The unexpected [{$command}] job was dispatched after sending the response."
        );
    }

    /**
     * Assert if a chain of jobs was dispatched.
     */
    public function assertChained(array $expectedChain): void
    {
        $command = $expectedChain[0];

        $expectedChain = array_slice($expectedChain, 1);

        $callback = null;

        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        } elseif ($command instanceof ChainedBatchTruthTest) {
            $instance = $command;

            $command = ChainedBatch::class;

            $callback = fn ($job) => $instance($job->toPendingBatch());
        } elseif (! is_string($command)) {
            $instance = $command;

            $command = get_class($instance);

            $callback = function ($job) use ($instance) {
                return serialize($this->resetChainPropertiesToDefaults($job)) === serialize($instance);
            };
        }

        PHPUnit::assertTrue(
            $this->dispatched($command, $callback)->isNotEmpty(),
            "The expected [{$command}] job was not dispatched."
        );

        $this->assertDispatchedWithChainOfObjects($command, $expectedChain, $callback);
    }

    /**
     * Assert no chained jobs was dispatched.
     */
    public function assertNothingChained(): void
    {
        $this->assertNothingDispatched();
    }

    /**
     * Reset the chain properties to their default values on the job.
     */
    protected function resetChainPropertiesToDefaults(mixed $job): mixed
    {
        return tap(clone $job, function ($job) {
            $job->chainConnection = null;
            $job->chainQueue = null;
            $job->chainCatchCallbacks = null;
            $job->chained = [];
        });
    }

    /**
     * Assert if a job was dispatched with an empty chain based on a truth-test callback.
     */
    public function assertDispatchedWithoutChain(Closure|string $command, ?callable $callback = null): void
    {
        if ($command instanceof Closure) {
            [$command, $callback] = [$this->firstClosureParameterType($command), $command];
        }

        PHPUnit::assertTrue(
            $this->dispatched($command, $callback)->isNotEmpty(),
            "The expected [{$command}] job was not dispatched."
        );

        $this->assertDispatchedWithChainOfObjects($command, [], $callback);
    }

    /**
     * Assert if a job was dispatched with chained jobs based on a truth-test callback.
     */
    protected function assertDispatchedWithChainOfObjects(string $command, array $expectedChain, ?callable $callback): void
    {
        $chain = $expectedChain;

        PHPUnit::assertTrue(
            $this->dispatched($command, $callback)->filter(function ($job) use ($chain) {
                if (count($chain) !== count($job->chained)) {
                    return false;
                }

                foreach ($job->chained as $index => $serializedChainedJob) {
                    if ($chain[$index] instanceof ChainedBatchTruthTest) {
                        $chainedBatch = unserialize($serializedChainedJob);

                        if (
                            ! $chainedBatch instanceof ChainedBatch
                            || ! $chain[$index]($chainedBatch->toPendingBatch())
                        ) {
                            return false;
                        }
                    } elseif ($chain[$index] instanceof Closure) {
                        [$expectedType, $callback] = [$this->firstClosureParameterType($chain[$index]), $chain[$index]];

                        $chainedJob = unserialize($serializedChainedJob);

                        if (! $chainedJob instanceof $expectedType) {
                            throw new RuntimeException('The chained job was expected to be of type ' . $expectedType . ', ' . $chainedJob::class . ' chained.');
                        }

                        if (! $callback($chainedJob)) {
                            return false;
                        }
                    } elseif (is_string($chain[$index])) {
                        if ($chain[$index] != get_class(unserialize($serializedChainedJob))) {
                            return false;
                        }
                    } elseif (serialize($chain[$index]) != $serializedChainedJob) {
                        return false;
                    }
                }

                return true;
            })->isNotEmpty(),
            'The expected chain was not dispatched.'
        );
    }

    /**
     * Create a new assertion about a chained batch.
     */
    public function chainedBatch(Closure $callback): ChainedBatchTruthTest
    {
        return new ChainedBatchTruthTest($callback);
    }

    /**
     * Assert if a batch was dispatched based on a truth-test callback.
     */
    public function assertBatched(callable $callback): void
    {
        PHPUnit::assertTrue(
            $this->batched($callback)->count() > 0,
            'The expected batch was not dispatched.'
        );
    }

    /**
     * Assert the number of batches that have been dispatched.
     */
    public function assertBatchCount(int $count): void
    {
        PHPUnit::assertCount(
            $count,
            $this->batches,
        );
    }

    /**
     * Assert that no batched jobs were dispatched.
     */
    public function assertNothingBatched(): void
    {
        $jobNames = Collection::make($this->batches)
            ->map(fn ($batch) => $batch->jobs->map(fn ($job) => get_class($job)))
            ->flatten()
            ->join("\n- ");

        PHPUnit::assertEmpty($this->batches, "The following batched jobs were dispatched unexpectedly:\n\n- {$jobNames}\n");
    }

    /**
     * Assert that no jobs were dispatched, chained, or batched.
     */
    public function assertNothingPlaced(): void
    {
        $this->assertNothingDispatched();
        $this->assertNothingBatched();
    }

    /**
     * Get all of the jobs matching a truth-test callback.
     */
    public function dispatched(string $command, ?callable $callback = null): Collection
    {
        if (! $this->hasDispatched($command)) {
            return Collection::make();
        }

        $callback = $callback ?: fn () => true;

        return Collection::make($this->commands[$command])->filter(fn ($command) => $callback($command));
    }

    /**
     * Get all of the jobs dispatched synchronously matching a truth-test callback.
     */
    public function dispatchedSync(string $command, ?callable $callback = null): Collection
    {
        if (! $this->hasDispatchedSync($command)) {
            return Collection::make();
        }

        $callback = $callback ?: fn () => true;

        return Collection::make($this->commandsSync[$command])->filter(fn ($command) => $callback($command));
    }

    /**
     * Get all of the jobs dispatched after the response was sent matching a truth-test callback.
     */
    public function dispatchedAfterResponse(string $command, ?callable $callback = null): Collection
    {
        if (! $this->hasDispatchedAfterResponse($command)) {
            return Collection::make();
        }

        $callback = $callback ?: fn () => true;

        return Collection::make($this->commandsAfterResponse[$command])->filter(fn ($command) => $callback($command));
    }

    /**
     * Get all of the pending batches matching a truth-test callback.
     */
    public function batched(callable $callback): Collection
    {
        if (empty($this->batches)) {
            return Collection::make();
        }

        return Collection::make($this->batches)->filter(fn ($batch) => $callback($batch));
    }

    /**
     * Determine if there are any stored commands for a given class.
     */
    public function hasDispatched(string $command): bool
    {
        return isset($this->commands[$command]) && ! empty($this->commands[$command]);
    }

    /**
     * Determine if there are any stored commands for a given class.
     */
    public function hasDispatchedSync(string $command): bool
    {
        return isset($this->commandsSync[$command]) && ! empty($this->commandsSync[$command]);
    }

    /**
     * Determine if there are any stored commands for a given class.
     */
    public function hasDispatchedAfterResponse(string $command): bool
    {
        return isset($this->commandsAfterResponse[$command]) && ! empty($this->commandsAfterResponse[$command]);
    }

    /**
     * Dispatch a command to its appropriate handler.
     */
    public function dispatch(mixed $command): mixed
    {
        if ($this->shouldFakeJob($command)) {
            return $this->commands[get_class($command)][] = $this->getCommandRepresentation($command);
        }
        return $this->dispatcher->dispatch($command);
    }

    /**
     * Dispatch a command to its appropriate handler in the current process.
     *
     * Queueable jobs will be dispatched to the "sync" queue.
     */
    public function dispatchSync(mixed $command, mixed $handler = null): mixed
    {
        if ($this->shouldFakeJob($command)) {
            return $this->commandsSync[get_class($command)][] = $this->getCommandRepresentation($command);
        }
        return $this->dispatcher->dispatchSync($command, $handler);
    }

    /**
     * Dispatch a command to its appropriate handler in the current process.
     */
    public function dispatchNow(mixed $command, mixed $handler = null): mixed
    {
        if ($this->shouldFakeJob($command)) {
            return $this->commands[get_class($command)][] = $this->getCommandRepresentation($command);
        }
        return $this->dispatcher->dispatchNow($command, $handler);
    }

    /**
     * Dispatch a command to its appropriate handler behind a queue.
     */
    public function dispatchToQueue(mixed $command): mixed
    {
        if ($this->shouldFakeJob($command)) {
            return $this->commands[get_class($command)][] = $this->getCommandRepresentation($command);
        }
        return $this->dispatcher->dispatchToQueue($command);
    }

    /**
     * Dispatch a command to its appropriate handler.
     */
    public function dispatchAfterResponse(mixed $command): mixed
    {
        if ($this->shouldFakeJob($command)) {
            return $this->commandsAfterResponse[get_class($command)][] = $this->getCommandRepresentation($command);
        }
        return $this->dispatcher->dispatch($command);
    }

    /**
     * Create a new chain of queueable jobs.
     */
    public function chain(array|Collection $jobs): PendingChain
    {
        $jobs = Collection::wrap($jobs);
        $jobs = ChainedBatch::prepareNestedBatches($jobs);

        return new PendingChainFake($this, $jobs->shift(), $jobs->toArray());
    }

    /**
     * Attempt to find the batch with the given ID.
     */
    public function findBatch(int|string $batchId): ?Batch
    {
        return $this->batchRepository->find($batchId);
    }

    /**
     * Create a new batch of queueable jobs.
     */
    public function batch(array|Collection $jobs): PendingBatch
    {
        return new PendingBatchFake($this, Collection::wrap($jobs));
    }

    /**
     * Dispatch an empty job batch for testing.
     */
    public function dispatchFakeBatch(string $name = ''): Batch
    {
        return $this->batch([])->name($name)->dispatch();
    }

    /**
     * Record the fake pending batch dispatch.
     */
    public function recordPendingBatch(PendingBatch $pendingBatch): Batch
    {
        $this->batches[] = $pendingBatch;

        return $this->batchRepository->store($pendingBatch);
    }

    /**
     * Determine if a command should be faked or actually dispatched.
     */
    protected function shouldFakeJob(mixed $command): bool
    {
        if ($this->shouldDispatchCommand($command)) {
            return false;
        }

        if (empty($this->jobsToFake)) {
            return true;
        }

        return Collection::make($this->jobsToFake)
            ->filter(function ($job) use ($command) {
                return $job instanceof Closure
                    ? $job($command)
                    : $job === get_class($command);
            })->isNotEmpty();
    }

    /**
     * Determine if a command should be dispatched or not.
     */
    protected function shouldDispatchCommand(mixed $command): bool
    {
        return Collection::make($this->jobsToDispatch)
            ->filter(function ($job) use ($command) {
                return $job instanceof Closure
                    ? $job($command)
                    : $job === get_class($command);
            })->isNotEmpty();
    }

    /**
     * Specify if commands should be serialized and restored when being batched.
     */
    public function serializeAndRestore(bool $serializeAndRestore = true): static
    {
        $this->serializeAndRestore = $serializeAndRestore;

        return $this;
    }

    /**
     * Serialize and unserialize the command to simulate the queueing process.
     */
    protected function serializeAndRestoreCommand(mixed $command): mixed
    {
        return unserialize(serialize($command));
    }

    /**
     * Return the command representation that should be stored.
     */
    protected function getCommandRepresentation(mixed $command): mixed
    {
        return $this->serializeAndRestore ? $this->serializeAndRestoreCommand($command) : $command;
    }

    /**
     * Set the pipes commands should be piped through before dispatching.
     */
    public function pipeThrough(array $pipes): static
    {
        $this->dispatcher->pipeThrough($pipes);

        return $this;
    }

    /**
     * Determine if the given command has a handler.
     */
    public function hasCommandHandler(mixed $command): bool
    {
        return $this->dispatcher->hasCommandHandler($command);
    }

    /**
     * Retrieve the handler for a command.
     */
    public function getCommandHandler(mixed $command): mixed
    {
        return $this->dispatcher->getCommandHandler($command);
    }

    /**
     * Map a command to a handler.
     */
    public function map(array $map): static
    {
        $this->dispatcher->map($map);

        return $this;
    }

    /**
     * Get the batches that have been dispatched.
     */
    public function dispatchedBatches(): array
    {
        return $this->batches;
    }
}
