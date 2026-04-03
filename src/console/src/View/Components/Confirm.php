<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

class Confirm extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $question, bool $default = false): bool
    {
        return $this->usingQuestionHelper(
            fn () => $this->output->confirm($question, $default),
        );
    }
}
