<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\Dispatchable;
use Hypervel\Bus\Queueable;
use Hypervel\Queue\Contracts\ShouldQueue;
use Laravel\SerializableClosure\SerializableClosure;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use Throwable;

class CallQueuedClosure implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The callbacks that should be executed on failure.
     */
    public array $failureCallbacks = [];

    /**
     * Indicate if the job should be deleted when models are missing.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected SerializableClosure $closure
    ) {
    }

    /**
     * Create a new job instance.
     */
    public static function create(Closure $job): static
    {
        return new static(new SerializableClosure($job));
    }

    /**
     * Execute the job.
     */
    public function handle(ContainerInterface $container): void
    {
        if (! method_exists($container, 'call')) {
            ($this->closure->getClosure())($this);
            return;
        }

        /** @var \Hypervel\Container\Contracts\Container $container */
        $container->call($this->closure->getClosure(), ['job' => $this]);
    }

    /**
     * Add a callback to be executed if the job fails.
     */
    public function onFailure(callable $callback): static
    {
        $this->failureCallbacks[] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;

        return $this;
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $e): void
    {
        foreach ($this->failureCallbacks as $callback) {
            $callback($e);
        }
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        $reflection = new ReflectionFunction($this->closure->getClosure());

        return 'Closure (' . basename($reflection->getFileName()) . ':' . $reflection->getStartLine() . ')';
    }
}
