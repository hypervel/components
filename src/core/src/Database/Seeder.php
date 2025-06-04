<?php

declare(strict_types=1);

namespace Hypervel\Database;

use FriendsOfHyperf\PrettyConsole\View\Components\TwoColumnDetail;
use Hypervel\Console\Command;
use Hypervel\Container\Contracts\Container as ContainerContract;
use Hypervel\Support\Arr;
use InvalidArgumentException;

use function Hyperf\Support\with;

abstract class Seeder
{
    /**
     * The container instance.
     */
    protected ContainerContract $container;

    /**
     * The console command instance.
     */
    protected Command $command;

    /**
     * Seeders that have been called at least one time.
     */
    protected static array $called = [];

    /**
     * Run the given seeder class.
     */
    public function call(array|string $class, bool $silent = false, array $parameters = []): static
    {
        $classes = Arr::wrap($class);

        foreach ($classes as $class) {
            $seeder = $this->resolve($class);

            $name = get_class($seeder);

            if ($silent === false && isset($this->command)) {
                with(new TwoColumnDetail($this->command->getOutput()))->render(
                    $name,
                    '<fg=yellow;options=bold>RUNNING</>'
                );
            }

            $startTime = microtime(true);

            $seeder->__invoke($parameters);

            if ($silent === false && isset($this->command)) {
                $runTime = number_format((microtime(true) - $startTime) * 1000);

                with(new TwoColumnDetail($this->command->getOutput()))->render(
                    $name,
                    "<fg=gray>$runTime ms</> <fg=green;options=bold>DONE</>"
                );

                $this->command->getOutput()->writeln('');
            }

            static::$called[] = $class;
        }

        return $this;
    }

    /**
     * Run the given seeder class.
     */
    public function callWith(array|string $class, array $parameters = []): void
    {
        $this->call($class, false, $parameters);
    }

    /**
     * Silently run the given seeder class.
     */
    public function callSilent(array|string $class, array $parameters = []): void
    {
        $this->call($class, true, $parameters);
    }

    /**
     * Run the given seeder class once.
     */
    public function callOnce(array|string $class, bool $silent = false, array $parameters = []): void
    {
        $classes = Arr::wrap($class);

        foreach ($classes as $class) {
            if (in_array($class, static::$called)) {
                continue;
            }

            $this->call($class, $silent, $parameters);
        }
    }

    /**
     * Resolve an instance of the given seeder class.
     */
    protected function resolve(string $class): Seeder
    {
        if (isset($this->container)) {
            $instance = $this->container->get($class);

            $instance->setContainer($this->container);
        } else {
            $instance = new $class;
        }

        if (isset($this->command)) {
            $instance->setCommand($this->command);
        }

        return $instance;
    }

    /**
     * Set the IoC container instance.
     */
    public function setContainer(ContainerContract $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Set the console command instance.
     */
    public function setCommand(Command $command): static
    {
        $this->command = $command;

        return $this;
    }

    /**
     * Run the database seeds.
     *
     * @throws InvalidArgumentException
     */
    public function __invoke(array $parameters = []): mixed
    {
        if (! method_exists($this, 'run')) {
            throw new InvalidArgumentException('Method [run] missing from ' . get_class($this));
        }

        $callback = fn () => isset($this->container)
            ? $this->container->call([$this, 'run'], $parameters)
            : $this->run(...$parameters); // @phpstan-ignore-line

        return $callback();
    }
}
