<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use __PHP_Incomplete_Class;
use Exception;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\DebounceLock;
use Hypervel\Bus\UniqueLock;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Pipeline\Pipeline;
use Hypervel\Queue\Events\JobDebounced;
use RuntimeException;
use Throwable;

class CallQueuedHandler
{
    /**
     * The command currently being processed.
     */
    protected mixed $runningCommand = null;

    /**
     * Create a new handler instance.
     */
    public function __construct(
        protected Dispatcher $dispatcher,
        protected Container $container
    ) {
    }

    /**
     * Handle the queued job.
     */
    public function call(Job $job, array $data): void
    {
        try {
            $command = $this->setJobInstanceIfNecessary(
                $job,
                $this->getCommand($data)
            );
        } catch (ModelNotFoundException $e) {
            $this->handleModelNotFound($job, $e);
            return;
        }

        if ($this->commandShouldBeDebounced($command)) {
            $this->deleteDebouncedJob($job, $command);

            return;
        }

        $this->runningCommand = $command;

        try {
            $this->dispatchThroughMiddleware($job, $command);
        } finally {
            $this->runningCommand = null;
        }

        if (! $job->isReleased() && ! $this->commandShouldBeUniqueUntilProcessing($command)) {
            $this->ensureUniqueJobLockIsReleased($command);
        }

        if (! $job->hasFailed() && ! $job->isReleased()) {
            $this->ensureNextJobInChainIsDispatched($command);
            $this->ensureSuccessfulBatchJobIsRecorded($command);
        }

        if (! $job->isDeletedOrReleased()) {
            $job->delete();
        }
    }

    /**
     * Get the command from the given payload.
     *
     * @throws RuntimeException
     */
    protected function getCommand(array $data): mixed
    {
        if (str_starts_with($data['command'], 'O:')) {
            return unserialize($data['command']);
        }

        if ($this->container->bound(Encrypter::class)) {
            return unserialize(
                $this->container->make(Encrypter::class)->decrypt($data['command'])
            );
        }

        throw new RuntimeException('Unable to extract job payload.');
    }

    /**
     * Dispatch the given job / command through its specified middleware.
     */
    protected function dispatchThroughMiddleware(Job $job, mixed $command): mixed
    {
        if ($command instanceof __PHP_Incomplete_Class) {
            throw new Exception('Job is incomplete class: ' . json_encode($command));
        }

        $lockReleased = false;

        return (new Pipeline($this->container))
            ->send($command)
            ->through(array_merge(method_exists($command, 'middleware') ? $command->middleware() : [], $command->middleware ?? []))
            ->finally(function ($command) use (&$lockReleased) {
                if (! $lockReleased && $this->commandShouldBeUniqueUntilProcessing($command) && ! $command->job->isReleased() && $command->job->attempts() <= 1) { /* @phpstan-ignore booleanNot.alwaysTrue ($lockReleased is set in then() which runs before finally()) */
                    $this->ensureUniqueJobLockIsReleased($command);
                }
            })
            ->then(function ($command) use ($job, &$lockReleased) {
                if ($this->commandShouldBeUniqueUntilProcessing($command) && $job->attempts() <= 1) {
                    $this->ensureUniqueJobLockIsReleased($command);

                    $lockReleased = true;
                }

                return $this->dispatcher->dispatchNow(
                    $command,
                    $this->resolveHandler($job, $command)
                );
            });
    }

    /**
     * Resolve the handler for the given command.
     */
    protected function resolveHandler(Job $job, mixed $command): mixed
    {
        $handler = $this->dispatcher->getCommandHandler($command) ?: null;

        if ($handler && in_array(InteractsWithQueue::class, class_uses_recursive($handler))) {
            // Mapped handlers may be worker-shared container instances. Clone the
            // configured handler before injecting the job owned by this execution.
            $handler = clone $handler;
            $handler->setJob($job);
        }

        return $handler;
    }

    /**
     * Set the job instance of the given class if necessary.
     */
    protected function setJobInstanceIfNecessary(Job $job, mixed $instance): mixed
    {
        if (in_array(InteractsWithQueue::class, class_uses_recursive($instance))) {
            $instance->setJob($job);
        }

        return $instance;
    }

    /**
     * Ensure the next job in the chain is dispatched if applicable.
     */
    protected function ensureNextJobInChainIsDispatched(mixed $command): void
    {
        if (method_exists($command, 'dispatchNextJobInChain')) {
            $command->dispatchNextJobInChain();
        }
    }

    /**
     * Ensure the batch is notified of the successful job completion.
     */
    protected function ensureSuccessfulBatchJobIsRecorded(mixed $command): void
    {
        $uses = class_uses_recursive($command);

        if (! in_array(Batchable::class, $uses)
            || ! in_array(InteractsWithQueue::class, $uses)
        ) {
            return;
        }

        if ($batch = $command->batch()) {
            $batch->recordSuccessfulJob($command->job->uuid());
        }
    }

    /**
     * Ensure the lock for a unique job is released.
     */
    protected function ensureUniqueJobLockIsReleased(mixed $command): void
    {
        if ($this->commandShouldBeUnique($command)) {
            (new UniqueLock($this->container->make(Cache::class)))->release($command);
        }
    }

    /**
     * Determine if the debounced command was superseded by a newer dispatch.
     */
    protected function commandShouldBeDebounced(mixed $command): bool
    {
        $owner = $command->debounceOwner ?? '';

        if ($owner === '') {
            return false;
        }

        $lock = new DebounceLock($this->container->make(Cache::class));
        $currentOwner = $lock->getCurrentOwner($command);

        // Fail open if the lock was evicted or expired before execution.
        if ($currentOwner === null) {
            return false;
        }

        return $currentOwner !== $owner;
    }

    /**
     * Handle a debounced job by firing an event and deleting it.
     */
    protected function deleteDebouncedJob(Job $job, mixed $command): void
    {
        if ($this->container->bound('events')) {
            /** @var EventDispatcher $events */
            $events = $this->container->make('events');

            if ($events->hasListeners(JobDebounced::class)) {
                $events->dispatch(new JobDebounced($job->getConnectionName(), $job, $command));
            }
        }

        $job->delete();
    }

    /**
     * Determine if the given command should be unique.
     */
    protected function commandShouldBeUnique(mixed $command): bool
    {
        return $command instanceof ShouldBeUnique
            || ($command instanceof CallQueuedListener && $command->shouldBeUnique());
    }

    /**
     * Determine if the given command should be unique until processing begins.
     */
    protected function commandShouldBeUniqueUntilProcessing(mixed $command): bool
    {
        return $command instanceof ShouldBeUniqueUntilProcessing
            || ($command instanceof CallQueuedListener && $command->shouldBeUniqueUntilProcessing());
    }

    /**
     * Handle a model not found exception.
     */
    protected function handleModelNotFound(Job $job, Throwable $e): void
    {
        $this->ensureUniqueJobLockIsReleasedViaContext();

        if ($job->payload()['deleteWhenMissingModels'] ?? false) {
            $this->ensureSuccessfulBatchJobIsRecordedForMissingModel($job, $job->resolveQueuedJobClass());

            $job->delete();

            return;
        }

        $job->fail($e);
    }

    /**
     * Ensure the lock for a unique job is released via context.
     *
     * This is required when we can't unserialize the job due to missing models.
     */
    protected function ensureUniqueJobLockIsReleasedViaContext(): void
    {
        if (! ContextRepository::hasInstance()
            || ! $this->container->bound(CacheFactory::class)
        ) {
            return;
        }

        $context = ContextRepository::getInstance();

        // IMPORTANT: Uses Laravel's keys for cross-framework queue interoperability.
        [$store, $key] = [
            $context->getHidden('laravel_unique_job_cache_store'),
            $context->getHidden('laravel_unique_job_key'),
        ];

        if ($store && $key) {
            $this->container->make(CacheFactory::class)
                ->store($store)
                ->lock($key) // @phpstan-ignore method.notFound (lock() is on LockProvider, which concrete stores implement)
                ->forceRelease();
        }
    }

    /**
     * Record a potentially batched job as successful when deleted because models were missing.
     */
    protected function ensureSuccessfulBatchJobIsRecordedForMissingModel(Job $job, string $class): void
    {
        if (! in_array(Batchable::class, class_uses_recursive($class), true)) {
            return;
        }

        if (! $this->container->bound(BatchRepository::class)) {
            return;
        }

        $batchId = $job->payload()['data']['batchId'] ?? null;

        if ((! is_string($batchId) || $batchId === '')
            || ! is_string($job->uuid()) || $job->uuid() === ''
        ) {
            return;
        }

        if ($batch = $this->container->make(BatchRepository::class)->find($batchId)) {
            $batch->recordSuccessfulJob($job->uuid());
        }
    }

    /**
     * Call the failed method on the job instance.
     *
     * The exception that caused the failure will be passed.
     */
    public function failed(array $data, ?Throwable $e, string $uuid, ?Job $job = null): void
    {
        $command = $this->getCommand($data);

        if (! is_null($job)) {
            $command = $this->setJobInstanceIfNecessary($job, $command);
        }

        if (! $this->commandShouldBeUniqueUntilProcessing($command)) {
            $this->ensureUniqueJobLockIsReleased($command);
        }

        if ($command instanceof __PHP_Incomplete_Class) {
            return;
        }

        $this->ensureFailedBatchJobIsRecorded($uuid, $command, $e);
        $this->ensureChainCatchCallbacksAreInvoked($uuid, $command, $e);

        if (method_exists($command, 'failed')) {
            $command->failed($e);
        }
    }

    /**
     * Ensure the batch is notified of the failed job.
     */
    protected function ensureFailedBatchJobIsRecorded(string $uuid, mixed $command, ?Throwable $e): void
    {
        if (! in_array(Batchable::class, class_uses_recursive($command))) {
            return;
        }

        if ($batch = $command->batch()) {
            $batch->recordFailedJob($uuid, $e);
        }
    }

    /**
     * Ensure the chained job catch callbacks are invoked.
     */
    protected function ensureChainCatchCallbacksAreInvoked(string $uuid, mixed $command, ?Throwable $e): void
    {
        if (method_exists($command, 'invokeChainCatchCallbacks')) {
            $command->invokeChainCatchCallbacks($e);
        }
    }

    /**
     * Get the command currently being processed.
     */
    public function getRunningCommand(): mixed
    {
        return $this->runningCommand;
    }
}
