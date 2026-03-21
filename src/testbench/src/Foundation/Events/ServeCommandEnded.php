<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Events;

use Hypervel\Console\View\Components\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

readonly class ServeCommandEnded
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public InputInterface $input,
        public OutputInterface $output,
        public Factory $components,
        public int $exitCode
    ) {
    }
}
