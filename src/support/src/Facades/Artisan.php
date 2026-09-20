<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Console\Kernel as ConsoleKernelContract;

/**
 * @method static \Hypervel\Foundation\Console\Kernel addCommandPaths(array $paths)
 * @method static \Hypervel\Foundation\Console\Kernel addCommandRoutePaths(array $paths)
 * @method static \Hypervel\Foundation\Console\Kernel addCommands(array $commands)
 * @method static array all()
 * @method static void bootstrap()
 * @method static void bootstrapWithoutBootingProviders()
 * @method static int call(string $command, array $parameters = [], \Symfony\Component\Console\Output\OutputInterface|null $outputBuffer = null)
 * @method static \Hypervel\Foundation\Console\ClosureCommand command(string $signature, \Closure $callback)
 * @method static void commands()
 * @method static \Hypervel\Support\CarbonImmutable|null commandStartedAt()
 * @method static \Symfony\Component\Console\Command\Command|null findCommand(string $name)
 * @method static \Hypervel\Contracts\Console\Application getArtisan()
 * @method static int handle(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface|null $output = null)
 * @method static void load(array|string $paths)
 * @method static string output()
 * @method static \Hypervel\Foundation\Bus\PendingDispatch queue(string $command, array $parameters = [])
 * @method static void registerCommand(\Symfony\Component\Console\Command\Command $command)
 * @method static \Hypervel\Console\Scheduling\Schedule resolveConsoleSchedule()
 * @method static int run(\Symfony\Component\Console\Input\InputInterface|null $input = null, \Symfony\Component\Console\Output\OutputInterface|null $output = null)
 * @method static void schedule(\Hypervel\Console\Scheduling\Schedule $schedule)
 * @method static void setArtisan(\Hypervel\Contracts\Console\Application|null $artisan)
 * @method static void terminate(\Symfony\Component\Console\Input\InputInterface $input, int $status)
 * @method static void whenCommandLifecycleIsLongerThan(\Carbon\CarbonInterval|\DateTimeInterface|int|float $threshold, callable $handler)
 *
 * @see \Hypervel\Foundation\Console\Kernel
 */
class Artisan extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ConsoleKernelContract::class;
    }
}
