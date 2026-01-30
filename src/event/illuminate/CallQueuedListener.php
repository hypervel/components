<?php

declare(strict_types=1);

namespace Illuminate\Events;

use DateInterval;
use DateTimeInterface;
use Hypervel\Context\ApplicationContext;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Throwable;

class CallQueuedListener implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

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
    public DateInterval|DateTimeInterface|int|null $retryUntil = null;

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
     * Create a new job instance.
     *
     * @param class-string $class the listener class name
     * @param string $method the listener method
     * @param array $data the data to be passed to the listener
     */
    public function __construct(
        public string $class,
        public string $method,
        public array $data
    ) {
    }

    public function handle(): void
    {
        $this->prepareData();

        $handler = $this->setJobInstanceIfNecessary(
            $this->job,
            ApplicationContext::getContainer()->get($this->class)
        );

        $handler->{$this->method}(...array_values($this->data));
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

        $handler = ApplicationContext::getContainer()->get($this->class);

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
        if (is_string($this->data)) { // @phpstan-ignore function.impossibleType (defensive: deserialization can bypass type checks)
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
    public function __clone()
    {
        $this->data = array_map(function ($data) {
            return is_object($data) ? clone $data : $data;
        }, $this->data);
    }
}
