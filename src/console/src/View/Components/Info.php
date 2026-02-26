<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;

class Info extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $string, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        (new Line($this->output))->render('info', $string, $verbosity);
    }
}
