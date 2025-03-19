<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console\Contracts;

use Closure;
use Hyperf\Command\Command;
use Hypervel\Container\Contracts\Container as ContainerContract;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface Application
{
    /**
     * Register a console "starting" bootstrapper.
     */
    public function starting(Closure $callback): void;

    /**
     * Clear the console application bootstrappers.
     */
    public function forgetBootstrappers(): void;

    public function getContainer(): ContainerContract;

    public function add(Command $command);

    public function all(?string $namespace = null);

    public function run(?InputInterface $input = null, ?OutputInterface $output = null);

    /**
     * Run an Artisan console command by name.
     *
     * @throws \Symfony\Component\Console\Exception\CommandNotFoundException
     */
    public function call(string $command, array $parameters = [], ?OutputInterface $outputBuffer = null): int;

    /**
     * Get the output for the last run command.
     */
    public function output(): string;

    /**
     * Add a command, resolving through the application.
     */
    public function resolve(Command|string $command): ?SymfonyCommand;

    /**
     * Resolve an array of commands through the application.
     *
     * @param array|mixed $commands
     * @return $this
     */
    public function resolveCommands($commands): static;

    /**
     * Set the container command loader for lazy resolution.
     *
     * @return $this
     */
    public function setContainerCommandLoader(): static;
}
