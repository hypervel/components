<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;

class TwoColumnDetail extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $first, ?string $second = null, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $mutators = [
            Mutators\EnsureDynamicContentIsHighlighted::class,
            Mutators\EnsureNoPunctuation::class,
            Mutators\EnsureRelativePaths::class,
        ];

        $first = $this->mutate($first, $mutators);

        if ($second !== null) {
            $second = $this->mutate($second, $mutators);
        }

        $this->renderView('two-column-detail', [
            'first' => $first,
            'second' => $second ?? '',
        ], $verbosity);
    }
}
