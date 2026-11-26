<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default;

use Hypervel\Prompts\DatePickerPrompt;

class DatePickerPromptRenderer extends Renderer
{
    use Concerns\DrawsBoxes;
    use Concerns\RendersSegments;

    /**
     * Render the date prompt.
     */
    public function __invoke(DatePickerPrompt $prompt): string
    {
        $maxWidth = $prompt->terminal()->cols() - 6;
        $weekday = $prompt->previewDate()->format('l');

        return match ($prompt->state) {
            'submit' => (string) $this
                ->box(
                    $this->dim($this->truncate($prompt->label, $maxWidth)),
                    $prompt->formattedValue(),
                    info: $weekday,
                ),

            'cancel' => (string) $this
                ->box(
                    $this->truncate($prompt->label, $maxWidth),
                    $this->strikethrough($this->dim($prompt->formattedValue())),
                    color: 'red',
                    info: $weekday,
                )
                ->error($prompt->cancelMessage),

            'error' => (string) $this
                ->box(
                    $this->truncate($prompt->label, $maxWidth),
                    $this->inputRow($prompt),
                    color: 'yellow',
                    info: $weekday,
                )
                ->warning($this->truncate($prompt->error, $prompt->terminal()->cols() - 5)),

            default => (string) $this
                ->box(
                    $this->cyan($this->truncate($prompt->label, $maxWidth)),
                    $this->inputRow($prompt),
                    info: $weekday,
                )
                ->when(
                    $prompt->hint,
                    fn () => $this->hint($prompt->hint),
                    fn () => $this->newLine() // Space for errors
                ),
        };
    }
}
