<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Hypervel\Console\View\Components\Mutators\EnsureDynamicContentIsHighlighted;
use Hypervel\Console\View\Components\Mutators\EnsureNoPunctuation;
use Hypervel\Console\View\Components\Mutators\EnsureRelativePaths;
use Stringable;
use Symfony\Component\Console\Output\OutputInterface;

class TwoColumnDetail extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(Stringable|string $first, Stringable|string|null $second = null, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $first = $this->mutate((string) $first, [
            EnsureDynamicContentIsHighlighted::class,
            EnsureNoPunctuation::class,
            EnsureRelativePaths::class,
        ]);

        if ($second !== null) {
            $second = $this->mutate((string) $second, [
                EnsureDynamicContentIsHighlighted::class,
                EnsureRelativePaths::class,
            ]);
        }

        $this->renderView('two-column-detail', [
            'first' => $first,
            'second' => $second ?? '',
        ], $verbosity);
    }
}
