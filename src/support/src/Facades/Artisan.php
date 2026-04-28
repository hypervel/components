<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Console\Kernel as ConsoleKernelContract;

/**
 * @method static mixed handle(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface|null $output = null)
 * @method static void bootstrap()
 * @method static void schedule(\Hypervel\Console\Scheduling\Schedule $schedule)
 * @method static \Hypervel\Console\Scheduling\Schedule resolveConsoleSchedule()
 * @method static void commands()
 * @method static \Hypervel\Foundation\Console\ClosureCommand command(string $signature, \Closure $callback)
 * @method static void load(array|string $paths)
 * @method static \Hypervel\Contracts\Console\Kernel addCommands(array $commands)
 * @method static \Hypervel\Contracts\Console\Kernel addCommandPaths(array $paths)
 * @method static \Hypervel\Contracts\Console\Kernel addCommandRoutePaths(array $paths)
 * @method static void registerCommand(\Symfony\Component\Console\Command\Command $command)
 * @method static void call(string $command, array $parameters = [], \Symfony\Component\Console\Output\OutputInterface|null $outputBuffer = null)
 * @method static \Hypervel\Foundation\Bus\PendingDispatch queue(string $command, array $parameters = [])
 * @method static array all()
 * @method static string output()
 * @method static void setArtisan(\Hypervel\Contracts\Console\Application|null $artisan)
 * @method static void terminate(\Symfony\Component\Console\Input\InputInterface $input, int $status)
 * @method static \Hypervel\Contracts\Console\Application getArtisan()
 *
 * @see \Hypervel\Contracts\Console\Kernel
 */
class Artisan extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ConsoleKernelContract::class;
    }
}
