<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use BackedEnum;
use Closure;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hyperf\Conditionable\Conditionable;
use Hyperf\Coroutine\Coroutine;
use Hypervel\Contracts\Bus\BatchRepository;
use Hypervel\Bus\Events\BatchDispatched;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler as ExceptionHandlerContract;
use Laravel\SerializableClosure\SerializableClosure;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function Hyperf\Support\value;
use function Hypervel\Support\enum_value;

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
     * Create a new pending batch instance.
     *
     * @param ContainerInterface $container the IoC container instance
     * @param Collection $jobs the jobs that belong to the batch
     */
    public function __construct(
        protected ContainerInterface $container,
        public Collection $jobs
    ) {
    }

    /**
     * Add jobs to the batch.
     *
     * @param array|iterable|object $jobs
     */
    public function add(iterable|object $jobs): static
    {
        $jobs = is_iterable($jobs) ? $jobs : Arr::wrap($jobs);

        foreach ($jobs as $job) {
            $this->jobs->push($job);
        }

        return $this;
    }

    /**
     * Add a callback to be executed when the batch is stored.
     */
    public function before(callable $callback): static
    {
        $this->options['before'][] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;

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
        $this->options['progress'][] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;

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
        $this->options['then'][] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;

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
        $this->options['catch'][] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;

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
        $this->options['finally'][] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;

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
     */
    public function allowFailures(bool $allowFailures = true): static
    {
        $this->options['allowFailures'] = $allowFailures;

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
    public function onConnection(string $connection): static
    {
        $this->options['connection'] = $connection;

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
    public function onQueue(BackedEnum|string|null $queue): static
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
        $repository = $this->container->get(BatchRepository::class);

        try {
            $batch = $this->store($repository);

            $batch = $batch->add($this->jobs);
        } catch (Throwable $e) {
            if (isset($batch)) {
                $repository->delete($batch->id);
            }

            throw $e;
        }

        $this->container->get(EventDispatcherInterface::class)
            ->dispatch(
                new BatchDispatched($batch)
            );

        return $batch;
    }

    /**
     * Dispatch the batch after the response is sent to the browser.
     */
    public function dispatchAfterResponse(): Batch
    {
        $repository = $this->container->get(BatchRepository::class);

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

        $this->container->get(EventDispatcherInterface::class)
            ->dispatch(
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

        Collection::make($this->beforeCallbacks())->each(function ($handler) use ($batch) {
            try {
                return $handler($batch);
            } catch (Throwable $e) {
                if (function_exists('report')) {
                    $this->container
                        ->get(ExceptionHandlerContract::class)
                        ->report($e);
                }
            }
        });

        return $batch;
    }
}
