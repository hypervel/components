<?php

declare(strict_types=1);

namespace Hypervel\Console;

use BadMethodCallException;
use Closure;
use Hypervel\Support\Traits\ForwardsCalls;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Support\Facades\Schedule;
use ReflectionFunction;

/**
 * @mixin \Hypervel\Console\Scheduling\Event
 */
class ClosureCommand extends Command
{
    use ForwardsCalls;

    /**
     * Create a new command instance.
     */
    public function __construct(
        protected ContainerContract $container,
        string $signature,
        protected Closure $callback
    ) {
        $this->signature = $signature;

        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $inputs = array_merge($this->input->getArguments(), $this->input->getOptions());

        $parameters = [];

        foreach ((new ReflectionFunction($this->callback))->getParameters() as $parameter) {
            if (isset($inputs[$parameter->getName()])) {
                $parameters[$parameter->getName()] = $inputs[$parameter->getName()];
            }
        }

        try {
            return (int) $this->container->call(
                $this->callback->bindTo($this, $this),
                $parameters
            );
        } catch (ManuallyFailedException $e) {
            $this->components->error($e->getMessage());

            return static::FAILURE;
        }
    }

    /**
     * Set the description for the command.
     */
    public function purpose(string $description): static
    {
        return $this->describe($description);
    }

    /**
     * Set the description for the command.
     */
    public function describe(string $description): static
    {
        $this->setDescription($description);

        return $this;
    }

    /**
     * Create a new scheduled event for the command.
     */
    public function schedule(array $parameters = []): Event
    {
        return Schedule::command($this->name, $parameters);
    }

    /**
     * Dynamically proxy calls to a new scheduled event.
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters)
    {
        return $this->forwardCallTo($this->schedule(), $method, $parameters);
    }
}
