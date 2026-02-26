<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;

class Line extends Component
{
    /**
     * The possible line styles.
     *
     * @var array<string, array<string, string>>
     */
    protected static array $styles = [
        'info' => [
            'bgColor' => 'blue',
            'fgColor' => 'white',
            'title' => 'info',
        ],
        'success' => [
            'bgColor' => 'green',
            'fgColor' => 'white',
            'title' => 'success',
        ],
        'warn' => [
            'bgColor' => 'yellow',
            'fgColor' => 'black',
            'title' => 'warn',
        ],
        'error' => [
            'bgColor' => 'red',
            'fgColor' => 'white',
            'title' => 'error',
        ],
    ];

    /**
     * Render the component using the given arguments.
     */
    public function render(string $style, string $string, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $string = $this->mutate($string, [
            Mutators\EnsureDynamicContentIsHighlighted::class,
            Mutators\EnsurePunctuation::class,
            Mutators\EnsureRelativePaths::class,
        ]);

        $this->renderView('line', array_merge(static::$styles[$style], [
            'marginTop' => max(0, 2 - $this->output->newLinesWritten()),
            'content' => $string,
        ]), $verbosity);
    }
}
