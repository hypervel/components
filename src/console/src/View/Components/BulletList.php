<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;

class BulletList extends Component
{
    /**
     * Render the component using the given arguments.
     *
     * @param array<int, string> $elements
     */
    public function render(array $elements, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $elements = $this->mutate($elements, [
            Mutators\EnsureDynamicContentIsHighlighted::class,
            Mutators\EnsureNoPunctuation::class,
            Mutators\EnsureRelativePaths::class,
        ]);

        $this->renderView('bullet-list', [
            'elements' => $elements,
        ], $verbosity);
    }
}
