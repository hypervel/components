<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Symfony\Component\Console\Question\Question;

class Secret extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $question, bool $fallback = true): mixed
    {
        $question = new Question($question);

        $question->setHidden(true)->setHiddenFallback($fallback);

        return $this->usingQuestionHelper(fn () => $this->output->askQuestion($question));
    }
}
