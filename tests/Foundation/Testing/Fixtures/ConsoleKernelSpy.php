<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Fixtures;

use Closure;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Console\Application;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Foundation\Console\ClosureCommand;
use LogicException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleKernelSpy implements Kernel
{
    /**
     * @var list<array{string, array}>
     */
    public array $calls = [];

    /**
     * @var list<?Application>
     */
    public array $setArtisanCalls = [];

    /**
     * Bootstrap the application for artisan commands.
     */
    public function bootstrap(): void
    {
    }

    /**
     * Handle an incoming console command.
     */
    public function handle(InputInterface $input, ?OutputInterface $output = null): int
    {
        return 0;
    }

    /**
     * Resolve a console schedule instance.
     */
    public function resolveConsoleSchedule(): Schedule
    {
        throw new LogicException('The console schedule cannot be resolved.');
    }

    /**
     * Register a Closure based command with the application.
     */
    public function command(string $signature, Closure $callback): ClosureCommand
    {
        throw new LogicException('Closure commands cannot be registered.');
    }

    /**
     * Set the Artisan commands provided by the application.
     */
    public function addCommands(array $commands): static
    {
        return $this;
    }

    /**
     * Set the paths that should have their Artisan commands automatically discovered.
     */
    public function addCommandPaths(array $paths): static
    {
        return $this;
    }

    /**
     * Set the paths that should have their Artisan "routes" automatically discovered.
     */
    public function addCommandRoutePaths(array $paths): static
    {
        return $this;
    }

    /**
     * Register the given command with the console application.
     */
    public function registerCommand(SymfonyCommand $command): void
    {
    }

    /**
     * Run an Artisan console command by name.
     */
    public function call(string $command, array $parameters = [], ?OutputInterface $outputBuffer = null): int
    {
        $this->calls[] = [$command, $parameters];

        return 0;
    }

    /**
     * Queue the given console command.
     */
    public function queue(string $command, array $parameters = []): PendingDispatch
    {
        throw new LogicException('Console commands cannot be queued.');
    }

    /**
     * Get all of the commands registered with the console.
     */
    public function all(): array
    {
        return [];
    }

    /**
     * Get the output for the last run command.
     */
    public function output(): string
    {
        return '';
    }

    /**
     * Terminate the application.
     */
    public function terminate(InputInterface $input, int $status): void
    {
    }

    /**
     * Set the Artisan application instance.
     */
    public function setArtisan(?Application $artisan): void
    {
        $this->setArtisanCalls[] = $artisan;
    }

    /**
     * Get the Artisan application instance.
     */
    public function getArtisan(): Application
    {
        throw new LogicException('The Artisan application cannot be resolved.');
    }
}
