<?php

declare(strict_types=1);

namespace Hypervel\Events;

use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Throwable;

class CallQueuedListener implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * The listener class name.
     *
     * @var class-string
     */
    public string $class;

    /**
     * The listener method.
     */
    public string $method;

    /**
     * The data to be passed to the listener.
     */
    public array|string $data;

    /**
     * The number of times the job may be attempted.
     */
    public ?int $tries = null;

    /**
     * The maximum number of exceptions allowed, regardless of attempts.
     */
    public ?int $maxExceptions = null;

    /**
     * The number of seconds to wait before retrying a job that encountered an uncaught exception.
     */
    public ?int $backoff = null;

    /**
     * The timestamp indicating when the job should timeout.
     */
    public ?int $retryUntil = null;

    /**
     * The number of seconds the job can run before timing out.
     */
    public ?int $timeout = null;

    /**
     * Indicates if the job should fail if the timeout is exceeded.
     */
    public bool $failOnTimeout = false;

    /**
     * Indicates if the job should be encrypted.
     */
    public bool $shouldBeEncrypted = false;

    /**
     * Indicates if the job should be deleted when models are missing.
     */
    public ?bool $deleteWhenMissingModels = null;

    /**
     * Indicates if the listener should be unique.
     */
    public bool $shouldBeUnique = false;

    /**
     * Indicates if the listener should be unique until processing begins.
     */
    public bool $shouldBeUniqueUntilProcessing = false;

    /**
     * The unique ID of the listener.
     */
    public mixed $uniqueId = null;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public ?int $uniqueFor = null;

    /**
     * Create a new job instance.
     *
     * @param class-string $class
     */
    public function __construct(string $class, string $method, array|string $data)
    {
        $this->data = $data;
        $this->class = $class;
        $this->method = $method;
    }

    /**
     * Handle the queued job.
     */
    public function handle(Container $container): void
    {
        $this->prepareData();

        $handler = $this->setJobInstanceIfNecessary(
            $this->job,
            $container->make($this->class)
        );

        $handler->{$this->method}(...array_values($this->data));
    }

    /**
     * Determine if the listener should be unique.
     */
    public function shouldBeUnique(): bool
    {
        return $this->shouldBeUnique;
    }

    /**
     * Determine if the listener should be unique until processing begins.
     */
    public function shouldBeUniqueUntilProcessing(): bool
    {
        return $this->shouldBeUniqueUntilProcessing;
    }

    /**
     * Get the unique ID for the listener.
     */
    public function uniqueId(): mixed
    {
        return $this->uniqueId;
    }

    /**
     * Get the number of seconds the unique lock should be maintained.
     */
    public function uniqueFor(): ?int
    {
        return $this->uniqueFor;
    }

    /**
     * Get the cache store used to manage unique locks.
     */
    public function uniqueVia(): ?Cache
    {
        $listener = Container::getInstance()->make($this->class);

        if (! method_exists($listener, 'uniqueVia')) {
            return null;
        }

        $this->prepareData();

        return $listener->uniqueVia(...array_values($this->data));
    }

    /**
     * Set the job instance of the given class if necessary.
     */
    protected function setJobInstanceIfNecessary(Job $job, object $instance): object
    {
        if (in_array(InteractsWithQueue::class, class_uses_recursive($instance))) {
            $instance->setJob($job);
        }

        return $instance;
    }

    /**
     * Call the failed method on the job instance.
     *
     * The event instance and the exception will be passed.
     */
    public function failed(Throwable $e): void
    {
        $this->prepareData();

        $handler = Container::getInstance()->make($this->class);

        $parameters = array_merge(array_values($this->data), [$e]);

        if (method_exists($handler, 'failed')) {
            $handler->failed(...$parameters);
        }
    }

    /**
     * Unserialize the data if needed.
     */
    protected function prepareData(): void
    {
        if (is_string($this->data)) {
            $this->data = unserialize($this->data);
        }
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->class;
    }

    /**
     * Prepare the instance for cloning.
     */
    public function __clone(): void
    {
        $this->data = array_map(function ($data) {
            return is_object($data) ? clone $data : $data;
        }, $this->data);
    }
}
