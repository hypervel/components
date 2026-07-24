<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface Application
{
    /**
     * Get the Hypervel application instance.
     */
    public function getHypervel(): ApplicationContract;

    /**
     * Add a command to the application.
     */
    public function add(SymfonyCommand $command): ?SymfonyCommand;

    /**
     * Get all of the commands registered with the application.
     */
    public function all(?string $namespace = null): array;

    /**
     * Run the console application.
     */
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int;

    /**
     * Run an Artisan console command by name.
     *
     * @throws \Symfony\Component\Console\Exception\CommandNotFoundException
     */
    public function call(string|SymfonyCommand $command, array $parameters = [], ?OutputInterface $outputBuffer = null): int;

    /**
     * Get the output for the last run command.
     */
    public function output(): string;

    /**
     * Add a command, resolving through the application.
     */
    public function resolve(SymfonyCommand|string $command): ?SymfonyCommand;

    /**
     * Resolve an array of commands through the application.
     *
     * @param array<string|SymfonyCommand>|string|SymfonyCommand ...$commands
     * @return $this
     */
    public function resolveCommands(array|SymfonyCommand|string ...$commands): static;

    /**
     * Set the container command loader for lazy resolution.
     *
     * @return $this
     */
    public function setContainerCommandLoader(): static;
}
