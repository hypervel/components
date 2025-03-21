<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use BadMethodCallException;
use Closure;
use Hyperf\Support\Traits\ForwardsCalls;
use Hypervel\Container\Contracts\Container as ContainerContract;
use Hypervel\Scheduling\Event;
use Hypervel\Support\Facades\Schedule;
use ReflectionFunction;

/**
 * @mixin \Hypervel\Scheduling\Event
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

        return (int) $this->container->call(
            $this->callback->bindTo($this, $this),
            $parameters
        );
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
