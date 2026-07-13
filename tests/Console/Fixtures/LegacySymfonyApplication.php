<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reproduce the Symfony Console application bundled with older Composer builds.
 *
 * The missing return types and addCommand() method are intentional parts of the
 * compatibility boundary exercised by ConsoleApplicationCompatibilityTest.
 */
class LegacySymfonyApplication
{
    public function add(Command $command)
    {
        return $command;
    }

    public function all(?string $namespace = null)
    {
        return [];
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null)
    {
        return 0;
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output)
    {
        return 0;
    }

    protected function getDefaultInputDefinition()
    {
        return new InputDefinition;
    }
}
