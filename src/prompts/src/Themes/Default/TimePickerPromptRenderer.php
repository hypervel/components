<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default;

use Hypervel\Prompts\TimePickerPrompt;

class TimePickerPromptRenderer extends Renderer
{
    use Concerns\DrawsBoxes;
    use Concerns\RendersSegments;

    /**
     * Render the time prompt.
     */
    public function __invoke(TimePickerPrompt $prompt): string
    {
        $maxWidth = $prompt->terminal()->cols() - 6;

        return match ($prompt->state) {
            'submit' => (string) $this->box($this->dim($this->truncate($prompt->label, $maxWidth)), $prompt->formattedValue()),
            'cancel' => (string) $this
                ->box($this->truncate($prompt->label, $maxWidth), $this->strikethrough($this->dim($prompt->formattedValue())), color: 'red')
                ->error($prompt->cancelMessage),
            'error' => (string) $this
                ->box($this->truncate($prompt->label, $maxWidth), $this->inputRow($prompt), color: 'yellow')
                ->warning($this->truncate($prompt->error, $prompt->terminal()->cols() - 5)),
            default => (string) $this
                ->box($this->cyan($this->truncate($prompt->label, $maxWidth)), $this->inputRow($prompt))
                ->when($prompt->hint, fn () => $this->hint($prompt->hint), fn () => $this->newLine()),
        };
    }
}
