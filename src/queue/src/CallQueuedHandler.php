<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use __PHP_Incomplete_Class;
use Exception;
use Hyperf\Database\Model\ModelNotFoundException;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\Contracts\Dispatcher;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\Contracts\Factory as CacheFactory;
use Hypervel\Encryption\Contracts\Encrypter;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\Contracts\Job;
use Hypervel\Queue\Contracts\ShouldBeUnique;
use Hypervel\Queue\Contracts\ShouldBeUniqueUntilProcessing;
use Hypervel\Support\Pipeline;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;

class CallQueuedHandler
{
    /**
     * Create a new handler instance.
     */
    public function __construct(
        protected Dispatcher $dispatcher,
        protected ContainerInterface $container
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

        if ($command instanceof ShouldBeUniqueUntilProcessing) {
            $this->ensureUniqueJobLockIsReleased($command);
        }

        $this->dispatchThroughMiddleware($job, $command);

        if (! $job->isReleased() && ! $command instanceof ShouldBeUniqueUntilProcessing) {
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

        if ($this->container->has(Encrypter::class)) {
            return unserialize(
                $this->container->get(Encrypter::class)->decrypt($data['command'])
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
        return (new Pipeline($this->container))
            ->send($command)
            ->through(array_merge(method_exists($command, 'middleware') ? $command->middleware() : [], $command->middleware ?? []))
            ->then(function ($command) use ($job) {
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

        if ($handler) {
            $this->setJobInstanceIfNecessary($job, $handler);
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
        if ($command instanceof ShouldBeUnique) {
            (new UniqueLock($this->container->get(CacheFactory::class)))->release($command);
        }
    }

    /**
     * Handle a model not found exception.
     */
    protected function handleModelNotFound(Job $job, Throwable $e): void
    {
        $class = $job->resolveName();

        try {
            $reflectionClass = new ReflectionClass($class);

            $shouldDelete = $reflectionClass->getDefaultProperties()['deleteWhenMissingModels']
                ?? count($reflectionClass->getAttributes(DeleteWhenMissingModels::class)) !== 0;
        } catch (Exception) {
            $shouldDelete = false;
        }

        if ($shouldDelete) {
            $job->delete();
            return;
        }

        $job->fail($e);
    }

    /**
     * Call the failed method on the job instance.
     *
     * The exception that caused the failure will be passed.
     */
    public function failed(array $data, ?Throwable $e, string $uuid): void
    {
        $command = $this->getCommand($data);

        if (! $command instanceof ShouldBeUniqueUntilProcessing) {
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
    protected function ensureFailedBatchJobIsRecorded(string $uuid, mixed $command, Throwable $e): void
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
    protected function ensureChainCatchCallbacksAreInvoked(string $uuid, mixed $command, Throwable $e): void
    {
        if (method_exists($command, 'invokeChainCatchCallbacks')) {
            $command->invokeChainCatchCallbacks($e);
        }
    }
}
