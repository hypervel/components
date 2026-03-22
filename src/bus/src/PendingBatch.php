<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;
use Hypervel\Bus\Events\BatchDispatched;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Conditionable;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;
use function value;

class PendingBatch
{
    use Conditionable;

    /**
     * The batch name.
     */
    public string $name = '';

    /**
     * The batch options.
     */
    public array $options = [];

    /**
     * Jobs that have been verified to contain the Batchable trait.
     *
     * @var array<class-string, bool>
     */
    protected static array $batchableClasses = [];

    /**
     * Create a new pending batch instance.
     */
    public function __construct(
        protected Container $container,
        public Collection $jobs,
    ) {
        $this->jobs = $jobs->filter()->values()->each(function (object|array $job) {
            $this->ensureJobIsBatchable($job);
        });
    }

    /**
     * Add jobs to the batch.
     */
    public function add(iterable|object $jobs): static
    {
        $jobs = is_iterable($jobs) ? $jobs : Arr::wrap($jobs);

        foreach ($jobs as $job) {
            $this->ensureJobIsBatchable($job);

            $this->jobs->push($job);
        }

        return $this;
    }

    /**
     * Ensure the given job is batchable.
     *
     * @throws RuntimeException
     */
    protected function ensureJobIsBatchable(object|array $job): void
    {
        foreach (Arr::wrap($job) as $job) {
            if ($job instanceof PendingBatch || $job instanceof Closure) {
                return;
            }

            if (! (static::$batchableClasses[$job::class] ?? false) && ! in_array(Batchable::class, class_uses_recursive($job))) {
                static::$batchableClasses[$job::class] = false;

                throw new RuntimeException(sprintf('Attempted to batch job [%s], but it does not use the Batchable trait.', $job::class));
            }

            static::$batchableClasses[$job::class] = true;
        }
    }

    /**
     * Add a callback to be executed when the batch is stored.
     */
    public function before(callable $callback): static
    {
        $this->registerCallback('before', $callback);

        return $this;
    }

    /**
     * Get the "before" callbacks that have been registered with the pending batch.
     */
    public function beforeCallbacks(): array
    {
        return $this->options['before'] ?? [];
    }

    /**
     * Add a callback to be executed after a job in the batch have executed successfully.
     */
    public function progress(callable $callback): static
    {
        $this->registerCallback('progress', $callback);

        return $this;
    }

    /**
     * Get the "progress" callbacks that have been registered with the pending batch.
     */
    public function progressCallbacks(): array
    {
        return $this->options['progress'] ?? [];
    }

    /**
     * Add a callback to be executed after all jobs in the batch have executed successfully.
     */
    public function then(callable $callback): static
    {
        $this->registerCallback('then', $callback);

        return $this;
    }

    /**
     * Get the "then" callbacks that have been registered with the pending batch.
     */
    public function thenCallbacks(): array
    {
        return $this->options['then'] ?? [];
    }

    /**
     * Add a callback to be executed after the first failing job in the batch.
     */
    public function catch(callable $callback): static
    {
        $this->registerCallback('catch', $callback);

        return $this;
    }

    /**
     * Get the "catch" callbacks that have been registered with the pending batch.
     */
    public function catchCallbacks(): array
    {
        return $this->options['catch'] ?? [];
    }

    /**
     * Add a callback to be executed after the batch has finished executing.
     */
    public function finally(callable $callback): static
    {
        $this->registerCallback('finally', $callback);

        return $this;
    }

    /**
     * Get the "finally" callbacks that have been registered with the pending batch.
     */
    public function finallyCallbacks(): array
    {
        return $this->options['finally'] ?? [];
    }

    /**
     * Indicate that the batch should not be cancelled when a job within the batch fails.
     *
     * Optionally, add callbacks to be executed upon each job failure.
     *
     * @param array<array-key, callable>|bool|callable $param
     */
    public function allowFailures(mixed $param = true): static
    {
        if (! is_bool($param)) {
            $param = Arr::wrap($param);

            foreach ($param as $callback) {
                if (is_callable($callback)) {
                    $this->registerCallback('failure', $callback);
                }
            }
        }

        $this->options['allowFailures'] = ! ($param === false);

        return $this;
    }

    /**
     * Determine if the pending batch allows jobs to fail without cancelling the batch.
     */
    public function allowsFailures(): bool
    {
        return Arr::get($this->options, 'allowFailures', false) === true;
    }

    /**
     * Get the "failure" callbacks that have been registered with the pending batch.
     *
     * @return array<array-key, callable|Closure>
     */
    public function failureCallbacks(): array
    {
        return $this->options['failure'] ?? [];
    }

    /**
     * Register a callback with proper serialization.
     */
    private function registerCallback(string $type, Closure|callable $callback): void
    {
        $this->options[$type][] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;
    }

    /**
     * Set the name for the batch.
     */
    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Specify the queue connection that the batched jobs should run on.
     */
    public function onConnection(UnitEnum|string $connection): static
    {
        $this->options['connection'] = enum_value($connection);

        return $this;
    }

    /**
     * Get the connection used by the pending batch.
     */
    public function connection(): ?string
    {
        return $this->options['connection'] ?? null;
    }

    /**
     * Specify the queue that the batched jobs should run on.
     */
    public function onQueue(UnitEnum|string|null $queue): static
    {
        $this->options['queue'] = enum_value($queue);

        return $this;
    }

    /**
     * Get the queue used by the pending batch.
     */
    public function queue(): ?string
    {
        return $this->options['queue'] ?? null;
    }

    /**
     * Add additional data into the batch's options array.
     */
    public function withOption(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Dispatch the batch.
     *
     * @throws Throwable
     */
    public function dispatch(): Batch
    {
        $repository = $this->container->make(BatchRepository::class);

        try {
            $batch = $this->store($repository);

            $batch = $batch->add($this->jobs);
        } catch (Throwable $e) {
            if (isset($batch)) {
                $repository->delete($batch->id);
            }

            throw $e;
        }

        $this->container->make(EventDispatcher::class)->dispatch(
            new BatchDispatched($batch)
        );

        return $batch;
    }

    /**
     * Dispatch the batch after the response is sent to the browser.
     */
    public function dispatchAfterResponse(): Batch
    {
        $repository = $this->container->make(BatchRepository::class);

        $batch = $this->store($repository);

        Coroutine::defer(fn () => $this->dispatchExistingBatch($batch));

        return $batch;
    }

    /**
     * Dispatch an existing batch.
     *
     * @throws Throwable
     */
    protected function dispatchExistingBatch(Batch $batch): void
    {
        try {
            $batch = $batch->add($this->jobs);
        } catch (Throwable $e) {
            $batch->delete();

            throw $e;
        }

        $this->container->make(EventDispatcher::class)->dispatch(
            new BatchDispatched($batch)
        );
    }

    /**
     * Dispatch the batch if the given truth test passes.
     */
    public function dispatchIf(bool|Closure $boolean): ?Batch
    {
        return value($boolean) ? $this->dispatch() : null;
    }

    /**
     * Dispatch the batch unless the given truth test passes.
     */
    public function dispatchUnless(bool|Closure $boolean): ?Batch
    {
        return ! value($boolean) ? $this->dispatch() : null;
    }

    /**
     * Store the batch using the given repository.
     */
    protected function store(BatchRepository $repository): Batch
    {
        $batch = $repository->store($this);

        (new Collection($this->beforeCallbacks()))->each(function ($handler) use ($batch) {
            try {
                return $handler($batch);
            } catch (Throwable $e) {
                if (function_exists('report')) {
                    report($e);
                }
            }
        });

        return $batch;
    }

    /**
     * Flush the internal state of the pending batch.
     */
    public static function flushState(): void
    {
        static::$batchableClasses = [];
    }
}
