<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reproduce the Symfony Console application bundled with Composer.
 *
 * The missing return types and addCommand() method are intentional parts of the
 * compatibility boundary exercised by ConsoleApplicationCompatibilityTest.
 */
class LegacySymfonyApplication
{
    /**
     * Add a command.
     */
    public function add(Command $command)
    {
        return $command;
    }

    /**
     * Get a registered command by name or alias.
     */
    public function get(string $name)
    {
        return new Command($name);
    }

    /**
     * Determine if a command exists.
     */
    public function has(string $name)
    {
        return false;
    }

    /**
     * Get the registered commands.
     */
    public function all(?string $namespace = null)
    {
        return [];
    }

    /**
     * Run the console application.
     */
    public function run(?InputInterface $input = null, ?OutputInterface $output = null)
    {
        return 0;
    }

    /**
     * Run the given command.
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output)
    {
        return 0;
    }

    /**
     * Get the default input definition.
     */
    protected function getDefaultInputDefinition()
    {
        return new InputDefinition;
    }
}
