<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CommandStarting
{
    /**
     * Create a new event instance.
     *
     * @param string $command the command name
     * @param \Symfony\Component\Console\Input\InputInterface $input the console input implementation
     * @param \Symfony\Component\Console\Output\OutputInterface $output the command output implementation
     */
    public function __construct(
        public string $command,
        public InputInterface $input,
        public OutputInterface $output,
    ) {
    }
}
