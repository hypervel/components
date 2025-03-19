<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console\Contracts;

use Closure;
use Hyperf\Command\ClosureCommand;
use Hypervel\Foundation\Console\Contracts\Application as ApplicationContract;
use Hypervel\Scheduling\Schedule;
use Symfony\Component\Console\Output\OutputInterface;

interface Kernel
{
    /**
     * Bootstrap the application for artisan commands.
     */
    public function bootstrap(): void;

    /**
     * Define the application's command schedule.
     */
    public function schedule(Schedule $schedule): void;

    /**
     * Register the commands for the application.
     */
    public function commands(): void;

    /**
     * Register a Closure based command with the application.
     */
    public function command(string $signature, Closure $callback): ClosureCommand;

    /**
     * Add loadPaths in the given directory.
     */
    public function load(array|string $paths): void;

    /**
     * Set the Artisan commands provided by the application.
     *
     * @return $this
     */
    public function addCommands(array $commands): static;

    /**
     * Set the paths that should have their Artisan commands automatically discovered.
     *
     * @return $this
     */
    public function addCommandPaths(array $paths): static;

    /**
     * Set the paths that should have their Artisan "routes" automatically discovered.
     *
     * @return $this
     */
    public function addCommandRoutePaths(array $paths): static;

    /**
     * Get loadedPaths for the application.
     */
    public function getLoadedPaths(): array;

    /**
     * Register the given command with the console application.
     */
    public function registerCommand(string $command);

    /**
     * Run an Artisan console command by name.
     *
     * @throws \Symfony\Component\Console\Exception\CommandNotFoundException
     */
    public function call(string $command, array $parameters = [], ?OutputInterface $outputBuffer = null);

    /**
     * Get all of the commands registered with the console.
     */
    public function all(): array;

    /**
     * Get the output for the last run command.
     */
    public function output(): string;

    /**
     * Set the Artisan application instance.
     */
    public function setArtisan(ApplicationContract $artisan): void;

    /**
     * Get the Artisan application instance.
     */
    public function getArtisan(): ApplicationContract;
}
