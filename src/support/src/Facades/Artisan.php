<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Console\Kernel as KernelContract;

/**
 * @method static void bootstrap()
 * @method static void schedule(\Hypervel\Console\Scheduling\Schedule $schedule)
 * @method static void commands()
 * @method static \Hypervel\Console\ClosureCommand command(string $signature, \Closure $callback)
 * @method static void load(array|string $paths)
 * @method static \Hypervel\Contracts\Console\Kernel addCommands(array $commands)
 * @method static \Hypervel\Contracts\Console\Kernel addCommandPaths(array $paths)
 * @method static \Hypervel\Contracts\Console\Kernel addCommandRoutePaths(array $paths)
 * @method static array getLoadedPaths()
 * @method static void registerCommand(string $command)
 * @method static void call(string $command, array $parameters = [], \Symfony\Component\Console\Output\OutputInterface|null $outputBuffer = null)
 * @method static array all()
 * @method static string output()
 * @method static void setArtisan(\Hypervel\Contracts\Console\Application $artisan)
 * @method static \Hypervel\Contracts\Console\Application getArtisan()
 *
 * @see \Hypervel\Contracts\Console\Kernel
 */
class Artisan extends Facade
{
    protected static function getFacadeAccessor()
    {
        return KernelContract::class;
    }
}
