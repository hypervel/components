<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;

class Alert extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $string, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $string = $this->mutate($string, [
            Mutators\EnsureDynamicContentIsHighlighted::class,
            Mutators\EnsurePunctuation::class,
            Mutators\EnsureRelativePaths::class,
        ]);

        $this->renderView('alert', [
            'content' => $string,
        ], $verbosity);
    }
}
