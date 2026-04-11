<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default;

use Hypervel\Prompts\Progress;

class ProgressRenderer extends Renderer
{
    use Concerns\DrawsBoxes;

    /**
     * The character to use for the progress bar.
     */
    protected string $barCharacter = '█';

    /**
     * Render the progress bar.
     *
     * @param Progress<int|iterable<mixed>> $progress
     */
    public function __invoke(Progress $progress): string
    {
        $filled = str_repeat($this->barCharacter, (int) ceil($progress->percentage() * min($this->minWidth, $progress->terminal()->cols() - 6)));

        return match ($progress->state) {
            'submit' => (string) $this
                ->box(
                    $this->dim($this->truncate($progress->label, $progress->terminal()->cols() - 6)),
                    $this->dim($filled),
                    info: $this->fractionCompleted($progress),
                ),

            'error' => (string) $this
                ->box(
                    $this->truncate($progress->label, $progress->terminal()->cols() - 6),
                    $this->dim($filled),
                    color: 'red',
                    info: $this->fractionCompleted($progress),
                ),

            'cancel' => (string) $this
                ->box(
                    $this->truncate($progress->label, $progress->terminal()->cols() - 6),
                    $this->dim($filled),
                    color: 'red',
                    info: $this->fractionCompleted($progress),
                )
                ->error($progress->cancelMessage),

            default => (string) $this
                ->box(
                    $this->cyan($this->truncate($progress->label, $progress->terminal()->cols() - 6)),
                    $this->dim($filled),
                    info: $this->fractionCompleted($progress),
                )
                ->when(
                    $progress->hint,
                    fn () => $this->hint($progress->hint),
                    fn () => $this->newLine() // Space for errors
                )
        };
    }

    /**
     * Get the formatted fraction completed.
     *
     * @param Progress<int|iterable<mixed>> $progress
     */
    protected function fractionCompleted(Progress $progress): string
    {
        return number_format($progress->progress) . ' / ' . number_format($progress->total);
    }
}
