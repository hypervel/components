<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\UniqueLock;
use Hypervel\Contracts\Cache\Factory as Cache;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Queue\ShouldBeEncrypted;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\FailOnTimeout;
use Hypervel\Queue\Attributes\MaxExceptions;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Queue\Attributes\Timeout;
use Hypervel\Queue\Attributes\Tries;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Exceptions\InvalidPayloadException;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Str;
use RuntimeException;
use Throwable;

use const JSON_UNESCAPED_UNICODE;

abstract class Queue
{
    use InteractsWithTime;
    use ReadsQueueAttributes;

    /**
     * The IoC container instance.
     */
    protected Container $container;

    /**
     * The connection name for the queue.
     */
    protected string $connectionName;

    /**
     * The original configuration for the queue.
     */
    protected array $config = [];

    /**
     * Indicates that jobs should be dispatched after all database transactions have committed.
     */
    protected ?bool $dispatchAfterCommit = false;

    /**
     * The create payload callbacks.
     *
     * @var callable[]
     */
    protected static $createPayloadCallbacks = [];

    /**
     * Push a new job onto the queue.
     */
    public function pushOn(?string $queue, object|string $job, mixed $data = ''): mixed
    {
        /* @phpstan-ignore-next-line */
        return $this->push($job, $data, $queue);
    }

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn(?string $queue, DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed
    {
        /* @phpstan-ignore-next-line */
        return $this->later($delay, $job, $data, $queue);
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        foreach ((array) $jobs as $job) {
            /* @phpstan-ignore-next-line */
            $this->push($job, $data, $queue);
        }

        return null;
    }

    /**
     * Create a payload string from the given job and data.
     *
     * @param Closure|object|string $job
     *
     * @throws InvalidPayloadException
     */
    protected function createPayload(array|object|string $job, ?string $queue, mixed $data = '', DateInterval|DateTimeInterface|int|null $delay = null): string
    {
        if ($job instanceof Closure) {
            $job = CallQueuedClosure::create($job);
        }

        $value = $this->createPayloadArray($job, $queue, $data);

        $value['delay'] = isset($delay)
            ? $this->secondsUntil($delay)
            : null;

        $payload = json_encode($value, JSON_UNESCAPED_UNICODE);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidPayloadException(
                'Unable to JSON encode payload. Error (' . json_last_error() . '): ' . json_last_error_msg(),
                $value
            );
        }

        return $payload;
    }

    /**
     * Create a payload array from the given job and data.
     */
    protected function createPayloadArray(array|object|string $job, ?string $queue, mixed $data = ''): array
    {
        return is_object($job)
            ? $this->createObjectPayload($job, $queue)
            : $this->createStringPayload($job, $queue, $data);
    }

    /**
     * Create a payload for an object-based queue handler.
     */
    protected function createObjectPayload(object $job, ?string $queue): array
    {
        $payload = $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Str::uuid(),
            'displayName' => $this->getDisplayName($job),
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => $this->getJobTries($job),
            'maxExceptions' => $this->getAttributeValue($job, MaxExceptions::class, 'maxExceptions'),
            'failOnTimeout' => $this->getAttributeValue($job, FailOnTimeout::class, 'failOnTimeout') ?? false,
            'backoff' => $this->getJobBackoff($job),
            'timeout' => $this->getAttributeValue($job, Timeout::class, 'timeout'),
            'retryUntil' => $this->getJobExpiration($job),
            'data' => [
                'commandName' => $job,
                'command' => $job,
                'batchId' => $job->batchId ?? null,
            ],
            'createdAt' => Carbon::now()->getTimestamp(),
        ]);

        try {
            $command = $this->jobShouldBeEncrypted($job) && $this->container->has(Encrypter::class)
                ? $this->container->make(Encrypter::class)->encrypt(serialize(clone $job))
                : serialize(clone $job);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Failed to serialize job of type [%s]: %s', get_class($job), $e->getMessage()),
                0,
                $e
            );
        }

        return array_merge($payload, [
            'data' => array_merge($payload['data'], [
                'commandName' => get_class($job),
                'command' => $command,
            ]),
        ]);
    }

    /**
     * Get the display name for the given job.
     */
    protected function getDisplayName(object $job): string
    {
        return method_exists($job, 'displayName')
            ? $job->displayName() : get_class($job);
    }

    /**
     * Get the maximum number of attempts for an object-based queue handler.
     */
    public function getJobTries(mixed $job): mixed
    {
        $tries = $this->getAttributeValue($job, Tries::class, 'tries');

        if (method_exists($job, 'tries')) {
            $tries = $job->tries();
        }

        return $tries;
    }

    /**
     * Get the backoff for an object-based queue handler.
     */
    public function getJobBackoff(mixed $job): mixed
    {
        $backoff = $this->getAttributeValue($job, Backoff::class, 'backoff');

        if (method_exists($job, 'backoff')) {
            $backoff = $job->backoff();
        }

        if (is_null($backoff)) {
            return null;
        }

        return Collection::wrap($backoff)
            ->map(fn ($backoff) => $backoff instanceof DateTimeInterface ? $this->secondsUntil($backoff) : $backoff)
            ->implode(',');
    }

    /**
     * Get the expiration timestamp for an object-based queue handler.
     */
    public function getJobExpiration(mixed $job): mixed
    {
        if (! method_exists($job, 'retryUntil') && ! isset($job->retryUntil)) {
            return null;
        }

        $expiration = $job->retryUntil ?? $job->retryUntil();

        return $expiration instanceof DateTimeInterface
            ? $expiration->getTimestamp() : $expiration;
    }

    /**
     * Determine if the job should be encrypted.
     */
    protected function jobShouldBeEncrypted(object $job): bool
    {
        if ($job instanceof ShouldBeEncrypted) {
            return true;
        }

        return isset($job->shouldBeEncrypted) && $job->shouldBeEncrypted;
    }

    /**
     * Create a typical, string based queue payload array.
     */
    protected function createStringPayload(array|string $job, ?string $queue, mixed $data): array
    {
        return $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Str::uuid(),
            'displayName' => is_string($job) ? explode('@', $job)[0] : null,
            'job' => $job,
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'data' => $data,
            'createdAt' => Carbon::now()->getTimestamp(),
        ]);
    }

    /**
     * Register a callback to be executed when creating job payloads.
     */
    public static function createPayloadUsing(?callable $callback): void
    {
        if (is_null($callback)) {
            static::$createPayloadCallbacks = [];
        } else {
            static::$createPayloadCallbacks[] = $callback;
        }
    }

    /**
     * Create the given payload using any registered payload hooks.
     */
    protected function withCreatePayloadHooks(?string $queue, array $payload): array
    {
        if (! empty(static::$createPayloadCallbacks)) {
            foreach (static::$createPayloadCallbacks as $callback) {
                $payload = array_merge($payload, $callback($this->getConnectionName(), $queue, $payload));
            }
        }

        return $payload;
    }

    /**
     * Enqueue a job using the given callback.
     *
     * @param Closure|object|string $job
     */
    protected function enqueueUsing(object|string $job, ?string $payload, ?string $queue, DateInterval|DateTimeInterface|int|null $delay, callable $callback): mixed
    {
        if ($this->shouldDispatchAfterCommit($job)
            && $this->container->has('db.transactions')
        ) {
            if ($job instanceof ShouldBeUnique) {
                $this->container->make('db.transactions')->addCallbackForRollback(
                    function () use ($job) {
                        (new UniqueLock($this->container->make(Cache::class)))->release($job);
                    }
                );
            }

            return $this->container->make('db.transactions')
                ->addCallback(
                    function () use ($queue, $job, $payload, $delay, $callback) {
                        $this->raiseJobQueueingEvent($queue, $job, $payload, $delay);

                        return tap($callback($payload, $queue, $delay), function ($jobId) use ($queue, $job, $payload, $delay) {
                            $this->raiseJobQueuedEvent($queue, $jobId, $job, $payload, $delay);
                        });
                    }
                );
        }

        $this->raiseJobQueueingEvent($queue, $job, $payload, $delay);

        return tap($callback($payload, $queue, $delay), function ($jobId) use ($queue, $job, $payload, $delay) {
            $this->raiseJobQueuedEvent($queue, $jobId, $job, $payload, $delay);
        });
    }

    /**
     * Determine if the job should be dispatched after all database transactions have committed.
     *
     * @param Closure|object|string $job
     */
    protected function shouldDispatchAfterCommit(object|string $job): bool
    {
        if ($job instanceof ShouldQueueAfterCommit) {
            return ! (isset($job->afterCommit) && $job->afterCommit === false);
        }

        if (! $job instanceof Closure && is_object($job) && isset($job->afterCommit)) {
            return $job->afterCommit;
        }

        return $this->dispatchAfterCommit ?? false;
    }

    /**
     * Raise the job queueing event.
     *
     * @param Closure|object|string $job
     */
    protected function raiseJobQueueingEvent(?string $queue, object|string $job, string $payload, DateInterval|DateTimeInterface|int|null $delay): void
    {
        if ($this->container->has(Dispatcher::class)) {
            $delay = ! is_null($delay) ? $this->secondsUntil($delay) : $delay;

            $this->container->make(Dispatcher::class)
                ->dispatch(new JobQueueing($this->connectionName, $queue, $job, $payload, $delay));
        }
    }

    /**
     * Raise the job queued event.
     *
     * @param Closure|object|string $job
     */
    protected function raiseJobQueuedEvent(?string $queue, mixed $jobId, object|string $job, string $payload, DateInterval|DateTimeInterface|int|null $delay): void
    {
        if ($this->container->has(Dispatcher::class)) {
            $delay = ! is_null($delay) ? $this->secondsUntil($delay) : $delay;

            $this->container->make(Dispatcher::class)
                ->dispatch(new JobQueued($this->connectionName, $queue, $jobId, $job, $payload, $delay));
        }
    }

    /**
     * Get the connection name for the queue.
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Set the connection name for the queue.
     */
    public function setConnectionName(string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    /**
     * Get the queue configuration array.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set the queue configuration array.
     */
    public function setConfig(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Get the container instance being used by the connection.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Set the IoC container instance.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }
}
