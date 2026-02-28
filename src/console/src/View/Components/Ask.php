<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Symfony\Component\Console\Question\Question;

class Ask extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $question, ?string $default = null, bool $multiline = false): mixed
    {
        return $this->usingQuestionHelper(
            fn () => $this->output->askQuestion(
                (new Question($question, $default))
                    ->setMultiline($multiline)
            )
        );
    }
}
