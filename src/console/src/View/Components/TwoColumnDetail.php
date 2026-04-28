<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Stringable;
use Symfony\Component\Console\Output\OutputInterface;

class TwoColumnDetail extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(Stringable|string $first, Stringable|string|null $second = null, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $mutators = [
            Mutators\EnsureDynamicContentIsHighlighted::class,
            Mutators\EnsureNoPunctuation::class,
            Mutators\EnsureRelativePaths::class,
        ];

        $first = $this->mutate((string) $first, $mutators);

        if ($second !== null) {
            $second = $this->mutate((string) $second, $mutators);
        }

        $this->renderView('two-column-detail', [
            'first' => $first,
            'second' => $second ?? '',
        ], $verbosity);
    }
}
