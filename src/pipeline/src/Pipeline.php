<?php

declare(strict_types=1);

namespace Hypervel\Pipeline;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pipeline\Pipeline as PipelineContract;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use RuntimeException;
use Throwable;
use UnitEnum;

class Pipeline implements PipelineContract
{
    use Conditionable;
    use Macroable;

    /**
     * The container implementation.
     */
    protected ?Container $container;

    /**
     * The object being passed through the pipeline.
     */
    protected mixed $passable = null;

    /**
     * The array of class pipes.
     */
    protected array $pipes = [];

    /**
     * The method to call on each pipe.
     */
    protected string $method = 'handle';

    /**
     * The final callback to be executed after the pipeline ends regardless of the outcome.
     */
    protected ?Closure $finally = null;

    /**
     * Indicates whether to wrap the pipeline in a database transaction.
     */
    protected string|UnitEnum|false|null $withinTransaction = false;

    /**
     * Create a new class instance.
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container;
    }

    /**
     * Set the object being sent through the pipeline.
     */
    public function send(mixed $passable): static
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * Set the array of pipes.
     */
    public function through(mixed $pipes): static
    {
        $this->pipes = is_array($pipes) ? $pipes : func_get_args();

        return $this;
    }

    /**
     * Push additional pipes onto the pipeline.
     */
    public function pipe(mixed $pipes): static
    {
        array_push($this->pipes, ...(is_array($pipes) ? $pipes : func_get_args()));

        return $this;
    }

    /**
     * Set the method to call on the pipes.
     */
    public function via(string $method): static
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Run the pipeline with a final destination callback.
     */
    public function then(Closure $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes()),
            $this->carry(),
            $this->prepareDestination($destination)
        );

        try {
            return $this->withinTransaction !== false
                ? $this->getContainer()->make('db')->connection($this->withinTransaction)->transaction(fn () => $pipeline($this->passable))
                : $pipeline($this->passable);
        } finally {
            if ($this->finally) {
                ($this->finally)($this->passable);
            }
        }
    }

    /**
     * Run the pipeline and return the result.
     */
    public function thenReturn(): mixed
    {
        return $this->then(function ($passable) {
            return $passable;
        });
    }

    /**
     * Set a final callback to be executed after the pipeline ends regardless of the outcome.
     */
    public function finally(Closure $callback): static
    {
        $this->finally = $callback;

        return $this;
    }

    /**
     * Get the final piece of the Closure onion.
     */
    protected function prepareDestination(Closure $destination): Closure
    {
        return function ($passable) use ($destination) {
            try {
                return $destination($passable);
            } catch (Throwable $e) {
                return $this->handleException($passable, $e);
            }
        };
    }

    /**
     * Get a Closure that represents a slice of the application onion.
     */
    protected function carry(): Closure
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                try {
                    if (is_callable($pipe)) {
                        // If the pipe is a callable, then we will call it directly, but otherwise we
                        // will resolve the pipes out of the dependency container and call it with
                        // the appropriate method and arguments, returning the results back out.
                        return $pipe($passable, $stack);
                    }
                    if (! is_object($pipe)) {
                        [$name, $parameters] = $this->parsePipeString($pipe);

                        // If the pipe is a string we will parse the string and resolve the class out
                        // of the dependency injection container. We can then build a callable and
                        // execute the pipe function giving in the parameters that are required.
                        $pipe = $this->getContainer()->make($name);

                        $parameters = array_merge([$passable, $stack], $parameters);
                    } else {
                        // If the pipe is already an object we'll just make a callable and pass it to
                        // the pipe as-is. There is no need to do any extra parsing and formatting
                        // since the object we're given was already a fully instantiated object.
                        $parameters = [$passable, $stack];
                    }

                    $carry = method_exists($pipe, $this->method)
                        ? $pipe->{$this->method}(...$parameters)
                        : $pipe(...$parameters);

                    return $this->handleCarry($carry);
                } catch (Throwable $e) {
                    return $this->handleException($passable, $e);
                }
            };
        };
    }

    /**
     * Parse full pipe string to get name and parameters.
     */
    protected function parsePipeString(string $pipe): array
    {
        [$name, $parameters] = array_pad(explode(':', $pipe, 2), 2, null);

        if (! is_null($parameters)) {
            $parameters = explode(',', $parameters);
        } else {
            $parameters = [];
        }

        return [$name, $parameters];
    }

    /**
     * Get the array of configured pipes.
     */
    protected function pipes(): array
    {
        return $this->pipes;
    }

    /**
     * Execute each pipeline step within a database transaction.
     */
    public function withinTransaction(string|UnitEnum|false|null $withinTransaction = null): static
    {
        $this->withinTransaction = $withinTransaction;

        return $this;
    }

    /**
     * Get the container instance.
     */
    protected function getContainer(): Container
    {
        if (! $this->container) {
            throw new RuntimeException('A container instance has not been passed to the Pipeline.');
        }

        return $this->container;
    }

    /**
     * Set the container instance.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Handle the value returned from each pipe before passing it to the next.
     */
    protected function handleCarry(mixed $carry): mixed
    {
        return $carry;
    }

    /**
     * Handle the given exception.
     *
     * @throws Throwable
     */
    protected function handleException(mixed $passable, Throwable $e): mixed
    {
        throw $e;
    }
}
