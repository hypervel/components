<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Closure;
use Hypervel\Process\Factory;

/**
 * @method static \Hypervel\Process\PendingProcess command(array|string $command)
 * @method static \Hypervel\Process\PendingProcess path(string $path)
 * @method static \Hypervel\Process\PendingProcess timeout(int $timeout)
 * @method static \Hypervel\Process\PendingProcess idleTimeout(int $timeout)
 * @method static \Hypervel\Process\PendingProcess forever()
 * @method static \Hypervel\Process\PendingProcess env(array $environment)
 * @method static \Hypervel\Process\PendingProcess input(null|bool|float|int|resource|string|\Traversable $input)
 * @method static \Hypervel\Process\PendingProcess quietly()
 * @method static \Hypervel\Process\PendingProcess tty(bool $tty = true)
 * @method static \Hypervel\Process\PendingProcess options(array $options)
 * @method static \Hypervel\Process\Contracts\ProcessResult run(array|string|null $command = null, callable|null $output = null)
 * @method static \Hypervel\Process\Contracts\InvokedProcess start(array|string|null $command = null, callable|null $output = null)
 * @method static \Hypervel\Process\PendingProcess withFakeHandlers(array $fakeHandlers)
 * @method static \Hypervel\Process\PendingProcess|mixed when(null|\Closure|mixed $value = null, null|callable $callback = null, null|callable $default = null, null|\Closure|mixed $value = null)
 * @method static \Hypervel\Process\PendingProcess|mixed unless(null|\Closure|mixed $value = null, null|callable $callback = null, null|callable $default = null, null|\Closure|mixed $value = null)
 * @method static \Hypervel\Process\FakeProcessResult result(array|string $output = '', array|string $errorOutput = '', int $exitCode = 0)
 * @method static \Hypervel\Process\FakeProcessDescription describe()
 * @method static \Hypervel\Process\FakeProcessSequence sequence(array $processes = [])
 * @method static bool isRecording()
 * @method static \Hypervel\Process\Factory recordIfRecording(\Hypervel\Process\PendingProcess $process, \Hypervel\Process\Contracts\ProcessResult $result)
 * @method static \Hypervel\Process\Factory record(\Hypervel\Process\PendingProcess $process, \Hypervel\Process\Contracts\ProcessResult $result)
 * @method static \Hypervel\Process\Factory preventStrayProcesses(bool $prevent = true)
 * @method static bool preventingStrayProcesses()
 * @method static \Hypervel\Process\Factory assertRan(\Closure|string $callback)
 * @method static \Hypervel\Process\Factory assertRanTimes(\Closure|string $callback, int $times = 1)
 * @method static \Hypervel\Process\Factory assertNotRan(\Closure|string $callback)
 * @method static \Hypervel\Process\Factory assertDidntRun(\Closure|string $callback)
 * @method static \Hypervel\Process\Factory assertNothingRan()
 * @method static \Hypervel\Process\Pool pool(callable $callback)
 * @method static \Hypervel\Process\Contracts\ProcessResult pipe(callable|array $callback, callable|null $output = null)
 * @method static \Hypervel\Process\ProcessPoolResults concurrently(callable $callback, callable|null $output = null)
 * @method static \Hypervel\Process\PendingProcess newPendingProcess()
 * @method static mixed macroCall(string $method, array $parameters)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 *
 * @see \Hypervel\Process\PendingProcess
 * @see \Hypervel\Process\Factory
 */
class Process extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor()
    {
        return Factory::class;
    }

    /**
     * Indicate that the process factory should fake processes.
     */
    public static function fake(array|Closure|null $callback = null): Factory
    {
        return tap(static::getFacadeRoot(), function ($fake) use ($callback) {
            static::swap($fake->fake($callback));
        });
    }
}
