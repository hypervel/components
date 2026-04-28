<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Hypervel\Console\OutputStyle;

class Confirm extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $question, bool $default = false): bool
    {
        /** @var OutputStyle $output */
        $output = $this->output;

        return $this->usingQuestionHelper(
            fn () => $output->confirm($question, $default),
        );
    }
}
